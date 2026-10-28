<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\DTOs\TranslationInput;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\BrandTranslation;
use App\Modules\Catalog\Models\Collection;
use App\Modules\Catalog\Models\CollectionTranslation;
use App\Modules\Catalog\Models\Tag;
use App\Modules\Catalog\Models\TagTranslation;
use App\Modules\Catalog\Services\TranslationWriter;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Transactions;
use InvalidArgumentException;

/** Create/update for the flat taxonomies: brands, tags and collections (same shape: flags + translations). */
final class SaveTaxonomyEntity
{
    /** @var array<class-string, array{0: class-string, 1: string, 2: string}> model => [translation model, fk, audit prefix] */
    private const MAP = [
        Brand::class => [BrandTranslation::class, 'brand_id', 'brand'],
        Tag::class => [TagTranslation::class, 'tag_id', 'tag'],
        Collection::class => [CollectionTranslation::class, 'collection_id', 'collection'],
    ];

    public function __construct(private readonly TranslationWriter $translations, private readonly AuditLogger $audit) {}

    /**
     * @param  class-string<Brand|Tag|Collection>  $modelClass
     * @param  list<TranslationInput>  $translations
     */
    public function handle(Actor $actor, string $modelClass, Brand|Tag|Collection|null $entity, array $translations, int $sortOrder = 0, bool $isActive = true): Brand|Tag|Collection
    {
        [$translationModel, $fk, $prefix] = self::MAP[$modelClass] ?? throw new InvalidArgumentException("Unsupported taxonomy [{$modelClass}].");

        return Transactions::run(function () use ($actor, $modelClass, $entity, $translations, $sortOrder, $isActive, $translationModel, $fk, $prefix): Brand|Tag|Collection {
            $isNew = $entity === null;
            $entity ??= new $modelClass;
            $entity->forceFill(['sort_order' => $sortOrder, 'is_active' => $isActive])->save();

            $this->translations->sync($entity, $translationModel, $fk, $translations);
            $this->audit->record($prefix.($isNew ? '.created' : '.updated'), $entity, after: ['sort_order' => $sortOrder, 'is_active' => $isActive], actor: $actor);

            return $entity;
        });
    }
}
