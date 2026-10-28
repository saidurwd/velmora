<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Exceptions\CatalogException;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Services\CategoryTree;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\StorefrontCache;
use App\Modules\Platform\Support\Transactions;

/** Soft-deletes a category that has no sub-categories (and, from slice 2, no products). The closure rows stay for history. */
final class DeleteCategory
{
    public function __construct(private readonly CategoryTree $tree, private readonly AuditLogger $audit, private readonly StorefrontCache $cache) {}

    public function handle(Actor $actor, Category $category): void
    {
        Transactions::run(function () use ($actor, $category): void {
            if ($this->tree->hasChildren($category)) {
                throw CatalogException::notEmpty('category');
            }

            $category->delete();
            $this->cache->bump();
            $this->audit->record('category.deleted', $category, actor: $actor);
        });
    }
}
