<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Notifications;

use App\Modules\IdentityAccess\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class VerifyEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'locale' => $notifiable->locale,
            'id' => $notifiable->public_id,
            'hash' => sha1((string) $notifiable->email),
        ]);

        return (new MailMessage)
            ->subject(__('identity-access::mail.verify.subject', ['app' => config('app.name')]))
            ->greeting(__('identity-access::mail.greeting'))
            ->line(__('identity-access::mail.verify.intro'))
            ->action(__('identity-access::mail.verify.action'), $url)
            ->line(__('identity-access::mail.verify.expiry', ['minutes' => 60]))
            ->line(__('identity-access::mail.ignore'));
    }
}
