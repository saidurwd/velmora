<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\DTOs\TranslationInput;
use App\Modules\Catalog\Exceptions\CatalogException;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Platform\Support\HtmlSanitizer;
use App\Modules\Platform\Support\Slugger;
use App\Modules\Platform\Support\SlugRedirects;
use App\Modules\Platform\Support\StorefrontCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Writes `<entity>_translations` rows (BD-30): English is mandatory, other languages optional; slugs are generated from
 * the name, unique per language, and an omitted language is removed (admin "clear this translation").
 */
final class TranslationWriter
{
    /** Translation table => the entity type used by slug redirects (tags have no public page). */
    private const REDIRECT_TYPES = ['product_translations' => 'product', 'category_translations' => 'category', 'brand_translations' => 'brand', 'collection_translations' => 'collection'];

    public function __construct(private readonly HtmlSanitizer $html, private readonly SlugRedirects $redirects, private readonly StorefrontCache $cache) {}

    /**
     * @param  class-string<Model>  $translationModel
     * @param  list<TranslationInput>  $inputs
     */
    public function sync(Model $entity, string $translationModel, string $foreignKey, array $inputs): void
    {
        $default = (string) config('app.default_locale');
        $supported = (array) config('app.supported_locales');
        $byLocale = [];

        foreach ($inputs as $input) {
            if (! in_array($input->locale, $supported, true)) {
                throw CatalogException::unsupportedLanguage($input->locale);
            }
            $byLocale[$input->locale] = $input;
        }

        if (! isset($byLocale[$default])) {
            throw CatalogException::defaultLanguageRequired($default);
        }

        $table = (new $translationModel)->getTable();

        DB::table($table)->where($foreignKey, $entity->getKey())->whereNotIn('locale', array_keys($byLocale))->delete();

        foreach ($byLocale as $locale => $input) {
            $slug = $this->uniqueSlug($table, $locale, Slugger::make($input->slug ?? $input->name), $foreignKey, (int) $entity->getKey(), $input->name);

            $values = [
                'name' => BanglaText::input($input->name),
                'slug' => $slug,
                'description' => $input->description === null ? null : $this->html->clean($input->description), // stored XSS stopped at write time
                'seo_title' => $input->seoTitle === null ? null : BanglaText::input($input->seoTitle),
                'seo_description' => $input->seoDescription === null ? null : BanglaText::input($input->seoDescription),
            ];

            if (Schema::hasColumn($table, 'short_description')) {
                $values['short_description'] = $input->shortDescription === null ? null : BanglaText::input($input->shortDescription);
            }

            $type = self::REDIRECT_TYPES[$table] ?? null;
            $previous = $type === null ? null : DB::table($table)->where($foreignKey, $entity->getKey())->where('locale', $locale)->value('slug');

            $translationModel::query()->updateOrCreate([$foreignKey => $entity->getKey(), 'locale' => $locale], $values);

            if ($type !== null) {
                $this->redirects->forget($type, $locale, $slug); // a slug in use again must not also redirect
                if ($previous !== null && $previous !== $slug) {
                    $this->redirects->record($type, (int) $entity->getKey(), $locale, (string) $previous);
                }
            }
        }

        $entity->unsetRelation('translations');
        $this->cache->bump();
    }

    private function uniqueSlug(string $table, string $locale, string $base, string $foreignKey, int $ownerId, string $fallbackName): string
    {
        $base = $base !== '' ? $base : 'item';
        $candidate = $base;

        for ($i = 2; DB::table($table)->where('locale', $locale)->where('slug', $candidate)->where($foreignKey, '!=', $ownerId)->exists(); $i++) {
            $suffix = '-'.$i;
            $candidate = mb_substr($base, 0, Slugger::MAX_LENGTH - mb_strlen($suffix)).$suffix;
        }

        return $candidate;
    }
}
