<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

/**
 * Search-engine and social metadata for the page being rendered. A controller fills in what it knows; the layout prints it.
 * Request-scoped (registered as a scoped singleton), so nothing leaks between requests.
 */
final class PageSeo
{
    public ?string $description = null;

    public ?string $canonical = null;

    /** @var array<string, string> hreflang => absolute URL of the SAME page in each language that really exists */
    public array $alternates = [];

    public bool $noindex = false;

    public ?string $image = null;

    public string $type = 'website';

    /** @var list<array<string, mixed>> JSON-LD objects */
    public array $jsonLd = [];

    public function describe(?string $text, int $limit = 160): self
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $this->description = $clean === '' ? null : (mb_strlen($clean) > $limit ? rtrim(mb_substr($clean, 0, $limit - 1)).'…' : $clean);

        return $this;
    }

    /** @param  array<string, string>  $urls  locale => absolute URL; x-default points at the default language when present */
    public function alternates(array $urls): self
    {
        $default = (string) config('app.default_locale');
        $this->alternates = $urls + (isset($urls[$default]) ? ['x-default' => $urls[$default]] : []);

        return $this;
    }

    /** @param  array<string, mixed>  $data */
    public function jsonLd(array $data): self
    {
        $this->jsonLd[] = ['@context' => 'https://schema.org'] + $data;

        return $this;
    }

    /**
     * Escaped so that no string inside can close the script element.
     *
     * @param  array<string, mixed>  $data
     */
    public static function encode(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
    }
}
