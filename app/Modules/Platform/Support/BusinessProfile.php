<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

/** The legal identity printed on receipts and packing slips. Shows only what staff have filled in; nothing here is invented or hard-coded. */
final class BusinessProfile
{
    public function __construct(private readonly Settings $settings) {}

    public function legalName(?string $locale = null): string
    {
        return $this->localised('legal_name', $locale);
    }

    public function address(?string $locale = null): string
    {
        return $this->localised('address', $locale);
    }

    public function invoiceFooter(?string $locale = null): string
    {
        return $this->localised('invoice_footer', $locale);
    }

    public function phone(): string
    {
        return $this->settings->string('business.phone');
    }

    public function email(): string
    {
        return $this->settings->string('business.email');
    }

    public function bin(): string
    {
        return $this->settings->string('business.bin');
    }

    public function tin(): string
    {
        return $this->settings->string('business.tin');
    }

    public function isEmpty(?string $locale = null): bool
    {
        return $this->legalName($locale) === '' && $this->address($locale) === '' && $this->phone() === '' && $this->email() === '' && $this->bin() === '' && $this->tin() === '' && $this->invoiceFooter($locale) === '';
    }

    /** The text exactly as saved for one language, without the fall-back (for the edit form). */
    public function localValue(string $name, string $locale): string
    {
        return trim($this->settings->string("business.{$name}.{$locale}"));
    }

    private function localised(string $name, ?string $locale): string
    {
        $locale ??= app()->getLocale();
        $value = trim($this->settings->string("business.{$name}.{$locale}"));

        return $value !== '' ? $value : trim($this->settings->string("business.{$name}.".config('app.default_locale')));
    }
}
