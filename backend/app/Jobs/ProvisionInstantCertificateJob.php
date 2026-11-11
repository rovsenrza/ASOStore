<?php

namespace App\Jobs;

use App\Models\Device;
use App\Services\InstantCerts\InstantCertificateException;
use App\Services\InstantCerts\InstantCertificates;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Gets an INSTANT customer's device its reseller certificate, outside the enrollment transaction. */
class ProvisionInstantCertificateJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public int $timeout = 280;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $deviceId) {}

    public function uniqueId(): string
    {
        return (string) $this->deviceId;
    }

    /** The buyer chose «instant» and is waiting: an admin must step in. */
    public function failed(?Throwable $exception): void
    {
        $device = Device::query()->with('user')->find($this->deviceId);
        if ($device !== null) {
            $this->tellAdmins($device, $exception?->getMessage() ?? 'unknown error');
        }
    }

    private function tellAdmins(Device $device, string $reason): void
    {
        try {
            app(\App\Services\TelegramStore\Bot\Messenger::class)->notifyAdmins(new \App\Services\TelegramStore\Bot\Screen(
                "⚠️ <b>Не удалось выдать мгновенный сертификат</b>\n\n"
                .'Аккаунт: '.e($device->user->email ?? '—')."\n"
                .'iPhone: '.e(($device->product ?? '?').' · '.($device->udid_hint ?? ''))."\n"
                .'Причина: <code>'.e(mb_substr($reason, 0, 300))."</code>\n\n"
                .'Покупатель ждёт. Повторить: <code>php artisan instant-certs:manage provision '.e($device->user->email ?? '').'</code>, или перевести на наши аккаунты: <code>instant-certs:manage off</code>.'
            ));
        } catch (Throwable $e) {
            Log::warning('instant_cert.alert_failed', ['device' => $device->id, 'message' => $e->getMessage()]);
        }
    }

    public function handle(InstantCertificates $instant): void
    {
        $device = Device::query()->with('user')->find($this->deviceId);
        if ($device === null || ! $instant->wantedFor($device)) {
            return;
        }
        try {
            if ($instant->provision($device) === null) {
                $this->release(30); // another worker holds the device's lock
            }
        } catch (InstantCertificateException $e) {
            Log::warning('instant_cert.failed', ['device' => $device->id, 'reason' => $e->reason, 'message' => $e->getMessage()]);
            if ($e->retryable()) {
                $this->release(max(60, $e->retryAfter));

                return;
            }
            Log::channel('alerts')->alert('instant_cert.refused', ['device' => $device->id, 'reason' => $e->reason, 'message' => $e->getMessage()]);
            $this->tellAdmins($device, $e->reason.': '.$e->getMessage());
        } catch (Throwable $e) {
            Log::channel('alerts')->alert('instant_cert.error', ['device' => $device->id, 'message' => $e->getMessage()]);
            throw $e;
        }
    }
}
