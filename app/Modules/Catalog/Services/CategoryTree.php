<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Exceptions\CatalogException;
use App\Modules\Catalog\Models\Category;
use Illuminate\Support\Facades\DB;

/**
 * Closure-table maintenance (ADR-026). Every node has a (self, self, 0) row and one row per ancestor, so subtree and
 * breadcrumb queries are single indexed joins. All writers call these inside a transaction.
 */
final class CategoryTree
{
    public const MAX_DEPTH = 4; // levels below the root: root = 1

    public function attach(Category $node, ?Category $parent): void
    {
        if ($parent !== null && $this->levelOf($parent) + 1 > self::MAX_DEPTH) {
            throw CatalogException::tooDeep(self::MAX_DEPTH);
        }

        DB::table('category_closure')->insert(['ancestor_id' => $node->id, 'descendant_id' => $node->id, 'depth' => 0]);

        if ($parent !== null) {
            DB::statement(
                'INSERT INTO category_closure (ancestor_id, descendant_id, depth) SELECT ancestor_id, ?, depth + 1 FROM category_closure WHERE descendant_id = ?',
                [$node->id, $parent->id],
            );
        }
    }

    /** Moves a node (and its whole subtree) under a new parent, or to the root with null. */
    public function move(Category $node, ?Category $newParent): void
    {
        if ($newParent !== null && ($newParent->is($node) || $this->isDescendantOf($newParent, $node))) {
            throw CatalogException::cycle();
        }

        $subtreeHeight = (int) DB::table('category_closure')->where('ancestor_id', $node->id)->max('depth');
        $newLevel = $newParent === null ? 1 : $this->levelOf($newParent) + 1;

        if ($newLevel + $subtreeHeight > self::MAX_DEPTH) {
            throw CatalogException::tooDeep(self::MAX_DEPTH);
        }

        // 1) cut the subtree away from every ancestor outside it (derived tables: MariaDB cannot read the table being deleted from)
        DB::statement(
            'DELETE FROM category_closure
              WHERE descendant_id IN (SELECT d FROM (SELECT descendant_id AS d FROM category_closure WHERE ancestor_id = ?) AS sub)
                AND ancestor_id NOT IN (SELECT d2 FROM (SELECT descendant_id AS d2 FROM category_closure WHERE ancestor_id = ?) AS sub2)',
            [$node->id, $node->id],
        );

        // 2) graft it under the new parent's ancestors (parent included)
        if ($newParent !== null) {
            DB::statement(
                'INSERT INTO category_closure (ancestor_id, descendant_id, depth)
                 SELECT p.ancestor_id, s.descendant_id, p.depth + s.depth + 1
                   FROM category_closure p JOIN category_closure s ON s.ancestor_id = ?
                  WHERE p.descendant_id = ?',
                [$node->id, $newParent->id],
            );
        }

        DB::table('categories')->where('id', $node->id)->update(['parent_id' => $newParent?->id, 'updated_at' => now('UTC')]);
        $node->parent_id = $newParent?->id;
    }

    /** 1 for a root, 2 for its children, ... */
    public function levelOf(Category $node): int
    {
        return (int) DB::table('category_closure')->where('descendant_id', $node->id)->max('depth') + 1;
    }

    public function isDescendantOf(Category $candidate, Category $ancestor): bool
    {
        return DB::table('category_closure')->where('ancestor_id', $ancestor->id)->where('descendant_id', $candidate->id)->where('depth', '>', 0)->exists();
    }

    /** @return list<int> the node and everything beneath it */
    public function subtreeIds(Category $node): array
    {
        return array_values(array_map('intval', DB::table('category_closure')->where('ancestor_id', $node->id)->pluck('descendant_id')->all()));
    }

    /** @return list<int> root first, the node last */
    public function pathIds(Category $node): array
    {
        return array_values(array_map('intval', DB::table('category_closure')->where('descendant_id', $node->id)->orderByDesc('depth')->pluck('ancestor_id')->all()));
    }

    public function hasChildren(Category $node): bool
    {
        return Category::query()->where('parent_id', $node->id)->exists();
    }
}
