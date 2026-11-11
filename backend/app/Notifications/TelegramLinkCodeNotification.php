<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The six-digit code that proves, in the Telegram bot, that an account is the customer's own.
 */
class TelegramLinkCodeNotification extends Notification
{
    use Queueable;

    public function __construct(
        #[\SensitiveParameter] public readonly string $code,
        public readonly int $minutes,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->code} — код для продления в Telegram · ".config('storefront.brand'))
            ->greeting('Здравствуйте!')
            ->line('Кто-то (надеемся, вы) продлевает подписку этого аккаунта в нашем Telegram-боте. Код подтверждения:')
            ->line("**{$this->code}**")
            ->line("Код действует {$this->minutes} минут. Отправьте его боту сообщением.")
            ->line('Если это были не вы, ничего делать не нужно: без кода никто не получит доступ к аккаунту.')
            ->salutation('Команда '.config('storefront.brand'));
    }
}
