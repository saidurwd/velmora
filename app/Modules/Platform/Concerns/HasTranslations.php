<?php

declare(strict_types=1);

namespace App\Modules\Platform\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Reads from normalised `<entity>_translations` tables (BD-30) with the fallback chain locale -> fallback (BD-32).
 * The using model declares translationModel() and (optionally) translationForeignKey().
 *
 * @mixin Model
 */
trait HasTranslations
{
    /** @return class-string<Model> */
    abstract protected function translationModel(): string;

    protected function translationForeignKey(): string
    {
        return $this->getForeignKey();
    }

    /** @return HasMany<Model, $this> */
    public function translations(): HasMany
    {
        return $this->hasMany($this->translationModel(), $this->translationForeignKey());
    }

    public function translate(string $field, ?string $locale = null): ?string
    {
        foreach ($this->localeChain($locale) as $candidate) {
            $row = $this->translations->firstWhere('locale', $candidate);
            $value = $row?->getAttribute($field);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    public function hasTranslation(string $field, string $locale): bool
    {
        $value = $this->translations->firstWhere('locale', $locale)?->getAttribute($field);

        return is_string($value) && $value !== '';
    }

    /** @return list<string> */
    protected function localeChain(?string $locale): array
    {
        return array_values(array_unique([
            $locale ?? app()->getLocale(),
            (string) config('app.fallback_locale', 'en'),
        ]));
    }
}
