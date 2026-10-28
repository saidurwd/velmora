<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class ConfirmNewEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $userPublicId,
        private readonly string $newEmail,
        private readonly string $oldEmailHash,
        private readonly string $recipientLocale,
    ) {}

    /** @return list<string> */
    public function via(AnonymousNotifiable $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(AnonymousNotifiable $notifiable): MailMessage
    {
        $url = URL::temporarySignedRoute('account.email.confirm', now()->addMinutes(60), [
            'locale' => $this->recipientLocale, 'user' => $this->userPublicId, 'new' => rtrim(strtr(base64_encode($this->newEmail), '+/', '-_'), '='), 'old' => $this->oldEmailHash,
        ]);

        return (new MailMessage)
            ->subject(__('identity-access::mail.email_change.subject', ['app' => config('app.name')], $this->recipientLocale))
            ->greeting(__('identity-access::mail.greeting', [], $this->recipientLocale))
            ->line(__('identity-access::mail.email_change.intro', [], $this->recipientLocale))
            ->action(__('identity-access::mail.email_change.action', [], $this->recipientLocale), $url)
            ->line(__('identity-access::mail.email_change.expiry', ['minutes' => 60], $this->recipientLocale))
            ->line(__('identity-access::mail.ignore', [], $this->recipientLocale));
    }
}
