<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Russian password-reset email pointing at the portal's reset page.
 */
class ResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(#[\SensitiveParameter] public readonly string $token) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = url('/reset-password.html').'?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);
        $minutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject('Сброс пароля — '.config('storefront.brand'))
            ->greeting('Здравствуйте!')
            ->line('Мы получили запрос на сброс пароля для вашего аккаунта.')
            ->action('Задать новый пароль', $url)
            ->line("Ссылка действует {$minutes} минут.")
            ->line('Если вы не запрашивали сброс, просто проигнорируйте это письмо — пароль останется прежним.')
            ->salutation('Команда '.config('storefront.brand'));
    }
}
