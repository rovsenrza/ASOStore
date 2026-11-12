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
use Throwable;

/**
 * Hourly: asks the reseller whether each instant certificate still works. A revoked or expired one
 * is marked so and the admins hear about it in the bot (nothing is bought again on its own: the
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
            try {
                $found = collect($api->certificates(UdidHasher::normalize((string) $device->udid_encrypted), true))
                    ->first(fn (array $row) => (string) ($row['id'] ?? '') === (string) $certificate->provider_certificate_id);
            } catch (Throwable $e) {
                $this->warn("certificate {$certificate->id}: {$e->getMessage()}");

                continue;
            }
            $state = match (true) {
                $found === null, ($found['status'] ?? null) === 'revoked', ($found['provision_valid'] ?? true) === false => 'REVOKED',
                ($found['status'] ?? null) === 'expired', ($found['expired'] ?? false) === true => 'EXPIRED',
                default => 'ACTIVE',
            };
            $certificate->forceFill(['status' => $state, 'checked_at' => now()])->save();
            if ($state === 'ACTIVE') {
                continue;
            }
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
     * The admin alert for a revoked/expired certificate: everything needed to act on it without
     * looking anything up (full UDID, certificate name, team, reseller id, ready commands).
     */
    public static function alertText(ExternalCertificate $certificate, string $state): string
    {
        $device = $certificate->device;
        $user = $device?->user;
        $active = $user?->activeSubscription()->first();
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
