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

        $certificates = ExternalCertificate::query()->with('device.user')->where('status', 'ACTIVE')
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
            $user = $device->user;
            $active = $user?->activeSubscription()->first();
            $this->warn("certificate {$certificate->id}: {$state} (device {$device->id})");
            $messenger->notifyAdmins(new Screen(
                ($state === 'REVOKED' ? '⚠️ <b>Мгновенный сертификат отозван</b>' : '⚠️ <b>Мгновенный сертификат истёк</b>')."\n\n"
                .'Аккаунт: '.e($user->email ?? '—')."\n"
                .'iPhone: '.e(($device->product ?? '?').' · '.($device->udid_hint ?? ''))."\n"
                .'Подписка: '.($active ? ($active->ends_at ? 'до '.$active->ends_at->format('d.m.Y') : 'без срока') : 'не активна')."\n"
                .'Гарантия продавца: '.($certificate->warranty_until ? 'до '.$certificate->warranty_until->format('d.m.Y') : '—')."\n\n"
                .'Приложения на этом iPhone перестанут открываться. Новый сертификат: <code>php artisan instant-certs:manage provision '.e($user->email ?? '').'</code>, или перевод на наши аккаунты: <code>instant-certs:manage off</code>.'
            ));
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
}
