<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Exceptions\CatalogException;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Transactions;

final class DeleteVariant
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Actor $actor, ProductVariant $variant): void
    {
        Transactions::run(function () use ($actor, $variant): void {
            /** @var Product $product */
            $product = Product::query()->whereKey($variant->product_id)->lockForUpdate()->firstOrFail();
            $others = ProductVariant::query()->where('product_id', $product->id)->whereKeyNot($variant->id)->where('status', 'active')->orderBy('sort_order')->orderBy('id');

            if ($product->status === ProductStatus::Published && ! (clone $others)->exists()) {
                throw CatalogException::lastVariant();
            }

            $wasDefault = $variant->is_default;
            $variant->forceFill(['is_default' => false])->save();
            $variant->delete();

            if ($wasDefault && ($next = (clone $others)->first()) !== null) {
                $next->forceFill(['is_default' => true])->save(); // there is always a default while variants remain
            }

            $this->audit->record('variant.deleted', $variant, ['sku' => $variant->sku], actor: $actor);
        });
    }
}
