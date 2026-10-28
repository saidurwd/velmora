<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Notifications;

use App\Modules\IdentityAccess\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $token) {}

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $url = ($notifiable->isStaff()
            ? route('admin.password.reset', ['token' => $this->token])
            : route('password.reset', ['locale' => $notifiable->locale, 'token' => $this->token])
        ).'?email='.urlencode((string) $notifiable->email);

        return (new MailMessage)
            ->subject(__('identity-access::mail.reset.subject', ['app' => config('app.name')]))
            ->greeting(__('identity-access::mail.greeting'))
            ->line(__('identity-access::mail.reset.intro'))
            ->action(__('identity-access::mail.reset.action'), $url)
            ->line(__('identity-access::mail.reset.expiry', ['minutes' => config('auth.passwords.users.expire')]))
            ->line(__('identity-access::mail.ignore'));
    }
}
