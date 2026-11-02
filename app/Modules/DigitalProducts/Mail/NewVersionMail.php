<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** "A new version of what you bought is ready." In the language the order was placed in. */
class NewVersionMail extends Mailable
{
    public function __construct(public readonly string $product, public readonly int $version, public readonly string $link, string $locale)
    {
        $this->locale($locale);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('digital-products::mail.new_version_subject', ['product' => $this->product]));
    }

    public function content(): Content
    {
        return new Content(view: 'digital-products::mail.new-version', with: ['product' => $this->product, 'version' => $this->version, 'link' => $this->link, 'locale' => $this->locale]);
    }
}
