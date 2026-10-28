<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Notifications;

use App\Modules\IdentityAccess\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StaffInviteNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $token, private readonly string $invitedBy) {}

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $url = route('admin.password.reset', ['token' => $this->token]).'?email='.urlencode((string) $notifiable->email);

        return (new MailMessage)
            ->subject(__('identity-access::mail.invite.subject', ['app' => config('app.name')]))
            ->greeting(__('identity-access::mail.greeting'))
            ->line(__('identity-access::mail.invite.intro', ['by' => $this->invitedBy, 'app' => config('app.name')]))
            ->action(__('identity-access::mail.invite.action'), $url)
            ->line(__('identity-access::mail.invite.expiry', ['minutes' => config('auth.passwords.staff.expire')]))
            ->line(__('identity-access::mail.invite.mfa'));
    }
}
