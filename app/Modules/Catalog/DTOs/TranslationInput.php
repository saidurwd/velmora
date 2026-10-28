<?php

declare(strict_types=1);

namespace App\Modules\Catalog\DTOs;

/** One language's content for a translatable catalog entity. `slug` is generated from `name` when empty. */
final readonly class TranslationInput
{
    public function __construct(
        public string $locale,
        public string $name,
        public ?string $slug = null,
        public ?string $description = null,
        public ?string $seoTitle = null,
        public ?string $seoDescription = null,
        public ?string $shortDescription = null,
    ) {}

    /**
     * @param  array<string, array<string, mixed>>  $byLocale  e.g. ['en' => ['name' => 'Shirts'], 'bn' => ['name' => 'শার্ট']]
     * @return list<self>
     */
    public static function fromArray(array $byLocale): array
    {
        $out = [];

        foreach ($byLocale as $locale => $data) {
            $name = trim((string) ($data['name'] ?? ''));

            if ($name === '') {
                continue; // an empty language is simply "not translated yet"
            }

            $out[] = new self(
                (string) $locale,
                $name,
                ($data['slug'] ?? '') === '' ? null : (string) $data['slug'],
                ($data['description'] ?? '') === '' ? null : (string) $data['description'],
                ($data['seo_title'] ?? '') === '' ? null : (string) $data['seo_title'],
                ($data['seo_description'] ?? '') === '' ? null : (string) $data['seo_description'],
                ($data['short_description'] ?? '') === '' ? null : (string) $data['short_description'],
            );
        }

        return $out;
    }
}
