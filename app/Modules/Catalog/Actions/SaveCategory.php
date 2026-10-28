<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\DTOs\TranslationInput;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\CategoryTranslation;
use App\Modules\Catalog\Services\CategoryTree;
use App\Modules\Catalog\Services\TranslationWriter;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Transactions;

final class SaveCategory
{
    public function __construct(private readonly TranslationWriter $translations, private readonly CategoryTree $tree, private readonly AuditLogger $audit) {}

    /** @param  list<TranslationInput>  $translations */
    public function handle(Actor $actor, ?Category $category, ?Category $parent, array $translations, int $sortOrder = 0, bool $isActive = true): Category
    {
        return Transactions::run(function () use ($actor, $category, $parent, $translations, $sortOrder, $isActive): Category {
            $isNew = $category === null;
            $before = $isNew ? [] : ['parent_id' => $category->parent_id, 'sort_order' => $category->sort_order, 'is_active' => $category->is_active];

            if ($isNew) {
                $category = new Category(['sort_order' => $sortOrder, 'is_active' => $isActive, 'parent_id' => $parent?->id]);
                $category->save();
                $this->tree->attach($category, $parent);
            } else {
                $category->forceFill(['sort_order' => $sortOrder, 'is_active' => $isActive])->save();

                if ($category->parent_id !== $parent?->id) {
                    $this->tree->move($category, $parent);
                }
            }

            $this->translations->sync($category, CategoryTranslation::class, 'category_id', $translations);

            $this->audit->record($isNew ? 'category.created' : 'category.updated', $category, $before, ['parent_id' => $category->parent_id, 'sort_order' => $sortOrder, 'is_active' => $isActive], $actor);

            return $category;
        });
    }
}
