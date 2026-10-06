<?php

namespace App\Notifications;

use App\Models\Device;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Russian email sent when Apple finishes processing a registered iPhone, so the customer
 * need not keep the registration page open while Apple takes minutes or hours.
 */
class DeviceReadyNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly Device $device) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $brand = (string) config('storefront.brand');

        return (new MailMessage)
            ->subject("Ваш iPhone готов — можно устанавливать {$brand}")
            ->greeting('Здравствуйте!')
            ->line("Apple подтвердил регистрацию вашего iPhone ({$this->device->maskedUdid()}).")
            ->line("Откройте страницу регистрации в Safari на этом iPhone и установите {$brand} — после этого приложения из каталога устанавливаются прямо из него.")
            ->action('Продолжить установку', rtrim((string) config('app.url'), '/').'/activate.html')
            ->line('Если вы не регистрировали это устройство, напишите в поддержку.')
            ->salutation("Команда {$brand}");
    }
}
