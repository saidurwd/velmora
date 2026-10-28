<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Notifications;

use App\Modules\IdentityAccess\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent when someone tries to register an address that already has an account (so the form can stay generic). */
class AccountAlreadyExistsNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('identity-access::mail.exists.subject', ['app' => config('app.name')]))
            ->greeting(__('identity-access::mail.greeting'))
            ->line(__('identity-access::mail.exists.intro'))
            ->action(__('identity-access::mail.exists.login'), route('login', ['locale' => $notifiable->locale]))
            ->line(__('identity-access::mail.exists.forgot', ['url' => route('password.request', ['locale' => $notifiable->locale])]))
            ->line(__('identity-access::mail.ignore'));
    }
}
