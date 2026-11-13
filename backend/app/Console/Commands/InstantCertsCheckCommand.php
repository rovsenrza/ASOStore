<?php

namespace App\Console\Commands;

use App\Models\ExternalCertificate;
use App\Services\InstantCerts\InstantCertificates;
use App\Services\InstantCerts\NekooClient;
use App\Services\TelegramStore\Bot\Messenger;
use App\Services\TelegramStore\Bot\Screen;
use App\Services\Devices\UdidHasher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Hourly: asks the reseller whether each instant certificate still works. A revoked or expired one
 * (twice in a row, an hour apart, each confirmed by a second full request) is marked so and the admins hear about it in the bot (nothing is bought again on its own: the
 * admin decides, e.g. `instant-certs:manage provision <email>`). Also warns when the prepaid
 * reseller balance runs low, since new instant customers would then be stuck.
 */
class InstantCertsCheckCommand extends Command
{
    protected $signature = 'instant-certs:check';

    protected $description = 'Check instant certificates at the reseller and warn admins about revoked ones and a low balance';

    public function handle(InstantCertificates $instant, NekooClient $api, Messenger $messenger): int
    {
        if (! $instant->enabled()) {
            return self::SUCCESS;
        }

        $certificates = ExternalCertificate::query()->with(['device.user', 'team'])->where('status', 'ACTIVE')
            ->where(fn ($query) => $query->whereNull('checked_at')->orWhere('checked_at', '<', now()->subMinutes(50)))
            ->limit(200)->get();
        foreach ($certificates as $certificate) {
            $device = $certificate->device;
            if ($device === null || ! $device->udid_encrypted) {
                continue;
            }
            $udid = UdidHasher::normalize((string) $device->udid_encrypted);
            try {
                [$state, $found] = $this->stateAtReseller($api, $udid, $certificate, true);
                if ($state !== 'ACTIVE') {
                    // The reseller's API sometimes answers wrong for a moment (2026-11-12: a working
                    // certificate came back as not valid and the customer lost installs for hours).
                    // Ask again in full before believing it.
                    sleep($this->retryDelaySeconds());
                    [$state, $found] = $this->stateAtReseller($api, $udid, $certificate, false);
                }
            } catch (Throwable $e) {
                $this->warn("certificate {$certificate->id}: {$e->getMessage()}");

                continue;
            }
            $strikeKey = "instant-certs:strike:{$certificate->id}";
            if ($state === 'ACTIVE') {
                Cache::forget($strikeKey);
                $certificate->forceFill(['checked_at' => now()])->save();

                continue;
            }
            Log::warning('instant_cert.check_bad', ['certificate' => $certificate->id, 'device' => $device->id, 'state' => $state, 'reseller' => $found === null ? null : collect($found)->except('p12', 'mobileprovision', 'provision')->all()]);
            // Only a second bad hourly check in a row counts: until then the certificate stays usable.
            $strikes = (int) Cache::get($strikeKey, 0) + 1;
            if ($strikes < 2) {
                Cache::put($strikeKey, $strikes, now()->addHours(3));
                $certificate->forceFill(['checked_at' => now()])->save();
                $this->warn("certificate {$certificate->id}: {$state} once (device {$device->id}), waiting for the next check");

                continue;
            }
            Cache::forget($strikeKey);
            $certificate->forceFill(['status' => $state, 'checked_at' => now()])->save();
            $this->warn("certificate {$certificate->id}: {$state} (device {$device->id})");
            $messenger->notifyAdmins(new Screen(self::alertText($certificate, $state)));
        }

        try {
            $balance = (float) ($api->me()['balance'] ?? 0);
            if ($balance < (float) config('instant_certs.low_balance_usd', 5) && Cache::add('instant-certs:low-balance-alert', true, now()->addHours(12))) {
                $messenger->notifyAdmins(new Screen(
                    "💳 <b>Баланс продавца сертификатов заканчивается</b>\n\n"
                    .'Осталось $'.number_format($balance, 2).'. Пополните его, иначе новые покупатели «⚡ Мгновенно» не получат сертификат.'
                ));
            }
        } catch (Throwable $e) {
            $this->warn('balance: '.$e->getMessage());
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: array<string, mixed>|null} ACTIVE | REVOKED | EXPIRED and the reseller's row
     */
    private function stateAtReseller(NekooClient $api, string $udid, ExternalCertificate $certificate, bool $light): array
    {
        $found = collect($api->certificates($udid, $light))
            ->first(fn (array $row) => (string) ($row['id'] ?? '') === (string) $certificate->provider_certificate_id);
        $state = match (true) {
            $found === null, ($found['status'] ?? null) === 'revoked', ($found['provision_valid'] ?? true) === false => 'REVOKED',
            ($found['status'] ?? null) === 'expired', ($found['expired'] ?? false) === true => 'EXPIRED',
            default => 'ACTIVE',
        };

        return [$state, $found];
    }

    private function retryDelaySeconds(): int
    {
        return app()->runningUnitTests() ? 0 : 10;
    }

    /**
     * The admin alert for a revoked/expired certificate: everything needed to act on it without
     * looking anything up (full UDID, certificate name, team, reseller id, ready commands).
     */
    public static function alertText(ExternalCertificate $certificate, string $state): string
    {
        $device = $certificate->device;
        $user = $device?->user;
        $active = $device?->activeSubscription()->first();
        $udid = $device && $device->udid_encrypted ? UdidHasher::normalize((string) $device->udid_encrypted) : '—';
        $email = (string) ($user->email ?? '');
        $artisan = 'cd /var/www/storefront/app/backend &amp;&amp; sudo -u storefront php artisan instant-certs:manage';
        $date = fn ($value) => $value ? $value->format('d.m.Y H:i') : '—';

        return ($state === 'REVOKED' ? '⚠️ <b>Мгновенный сертификат отозван</b>' : '⚠️ <b>Мгновенный сертификат истёк</b>')."\n\n"
            .'Аккаунт: '.e($email !== '' ? $email : '—').($user ? ' (user #'.$user->id.')' : '')."\n"
            .'Устройство: '.e(($device->product ?? '?').($device?->name ? ' «'.$device->name.'»' : '')).' (device #'.($device->id ?? '?').')'."\n"
            .'UDID: <code>'.e($udid).'</code>'."\n\n"
            .'Сертификат: <code>'.e($certificate->common_name ?? '—').'</code>'."\n"
            .'Team ID: <code>'.e($certificate->team->apple_team_id ?? '—').'</code>'."\n"
            .'ID у продавца: <code>'.e((string) ($certificate->provider_certificate_id ?? '—')).'</code> ('.e($certificate->provider ?? '?').', план '.e($certificate->plan ?? '—').')'."\n"
            .'SHA-1: <code>'.e($certificate->sha1_fingerprint ?? '—').'</code>'."\n"
            .'Профиль до: '.$date($certificate->profile_expires_at)."\n"
            .'Гарантия продавца: '.$date($certificate->warranty_until)."\n"
            .'Подписка: '.($active ? ($active->ends_at ? 'до '.$active->ends_at->format('d.m.Y') : 'без срока') : 'не активна')."\n\n"
            .'Приложения на этом устройстве перестанут открываться.'."\n\n"
            .'Новый сертификат:'."\n<code>{$artisan} provision ".e($email).'</code>'."\n\n"
            .'Перевод на наши аккаунты:'."\n<code>{$artisan} off ".e($email).'</code>';
    }
}
