<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** An email made from a template: a one-line subject and plain-text words, which the layout escapes and lays out. */
class NotificationMail extends Mailable
{
    public function __construct(public readonly string $subjectLine, public readonly string $text, string $locale)
    {
        $this->locale($locale);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'notifications::mail.message', with: ['text' => $this->text, 'locale' => $this->locale]);
    }
}
