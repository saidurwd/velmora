<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Notifications;

use App\Modules\IdentityAccess\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "Your password/email was changed" alert, so a hijack is noticed. Goes to the OLD address on an email change. */
class SecurityChangeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $what, private readonly ?string $recipientLocale = null) {}

    /** @return list<string> */
    public function via(User|AnonymousNotifiable $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User|AnonymousNotifiable $notifiable): MailMessage
    {
        $locale = $notifiable instanceof User ? $notifiable->locale : ($this->recipientLocale ?? config('app.default_locale'));

        return (new MailMessage)
            ->subject(__('identity-access::mail.changed.subject_'.$this->what, ['app' => config('app.name')], $locale))
            ->greeting(__('identity-access::mail.greeting', [], $locale))
            ->line(__('identity-access::mail.changed.body_'.$this->what, [], $locale))
            ->line(__('identity-access::mail.changed.not_you', [], $locale));
    }
}
