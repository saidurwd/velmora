<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A message about a booking (a reminder, or "how was it?"), in the language the order was placed in. */
class BookingMail extends Mailable
{
    public function __construct(public readonly string $subjectLine, public readonly string $heading, public readonly string $body, public readonly ?string $link, string $locale)
    {
        $this->locale($locale);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'bookings::mail.message', with: ['heading' => $this->heading, 'body' => $this->body, 'link' => $this->link, 'locale' => $this->locale]);
    }
}
