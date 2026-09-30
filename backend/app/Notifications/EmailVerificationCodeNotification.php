<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Russian email with the six-digit code that confirms a new account's address.
 */
class EmailVerificationCodeNotification extends Notification
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
            ->subject("{$this->code} — код подтверждения ".config('storefront.brand'))
            ->greeting('Здравствуйте!')
            ->line('Ваш код подтверждения эл. почты:')
            ->line("**{$this->code}**")
            ->line("Код действует {$this->minutes} минут. Введите его на странице подтверждения.")
            ->line('Если вы не регистрировались, просто проигнорируйте это письмо.')
            ->salutation('Команда '.config('storefront.brand'));
    }
}
