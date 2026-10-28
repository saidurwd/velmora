<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Models\ProductMedia;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\Storage;

/** Ordering, primary image and removal for a product's gallery. */
final class ManageProductMedia
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function makePrimary(Actor $actor, ProductMedia $media): void
    {
        Transactions::run(function () use ($actor, $media): void {
            ProductMedia::query()->where('product_id', $media->product_id)->where('is_primary', true)->whereKeyNot($media->id)->update(['is_primary' => false]);
            $media->forceFill(['is_primary' => true])->save();
            $this->audit->record('media.primary_changed', $media, actor: $actor);
        });
    }

    /** @param  list<string>  $publicIds  gallery order, first = shown first */
    public function reorder(Actor $actor, int $productId, array $publicIds): void
    {
        Transactions::run(function () use ($actor, $productId, $publicIds): void {
            foreach ($publicIds as $position => $publicId) {
                ProductMedia::query()->where('product_id', $productId)->where('public_id', $publicId)->update(['sort_order' => $position + 1]);
            }
            $this->audit->record('media.reordered', 'product', after: ['order' => $publicIds], actor: $actor, subjectId: $productId);
        });
    }

    public function delete(Actor $actor, ProductMedia $media): void
    {
        Transactions::run(function () use ($actor, $media): void {
            $wasPrimary = $media->is_primary;
            $media->forceFill(['is_primary' => false])->save();
            $media->delete();

            if ($wasPrimary && ($next = ProductMedia::query()->where('product_id', $media->product_id)->orderBy('sort_order')->first()) !== null) {
                $next->forceFill(['is_primary' => true])->save(); // a product with images always has a primary one
            }

            $this->audit->record('media.deleted', $media, actor: $actor);
        });

        Storage::disk($media->disk)->deleteDirectory($media->base_path);
    }
}
