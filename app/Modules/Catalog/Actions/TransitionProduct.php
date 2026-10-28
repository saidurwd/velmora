<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Exceptions\CatalogException;
use App\Modules\Catalog\Models\Product;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Transactions;
use App\Modules\Pricing\Contracts\PriceCatalog;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/** draft → published (now or scheduled) → draft / archived. Publishing is validated; nothing half-built goes live. */
final class TransitionProduct
{
    public function __construct(private readonly AuditLogger $audit, private readonly Clock $clock, private readonly PriceCatalog $prices) {}

    public function publish(Actor $actor, Product $product): Product
    {
        return $this->apply($actor, $product, function (Product $p): void {
            $this->assertPublishable($p);
            $p->forceFill(['status' => ProductStatus::Published, 'published_at' => $this->clock->now(), 'publish_at' => null])->save();
        }, 'product.published');
    }

    public function schedule(Actor $actor, Product $product, CarbonInterface $at): Product
    {
        if ($at->lessThanOrEqualTo($this->clock->now())) {
            throw CatalogException::notPublishable('the scheduled time must be in the future');
        }

        return $this->apply($actor, $product, function (Product $p) use ($at): void {
            $this->assertPublishable($p);
            $p->forceFill(['publish_at' => $at])->save();
        }, 'product.scheduled');
    }

    public function unpublish(Actor $actor, Product $product): Product
    {
        return $this->apply($actor, $product, fn (Product $p) => $p->forceFill(['status' => ProductStatus::Draft, 'publish_at' => null])->save(), 'product.unpublished');
    }

    public function archive(Actor $actor, Product $product): Product
    {
        return $this->apply($actor, $product, fn (Product $p) => $p->forceFill(['status' => ProductStatus::Archived, 'publish_at' => null])->save(), 'product.archived');
    }

    private function apply(Actor $actor, Product $product, callable $change, string $action): Product
    {
        return Transactions::run(function () use ($actor, $product, $change, $action): Product {
            /** @var Product $locked */
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status->value;

            $change($locked);
            $this->audit->record($action, $locked, ['status' => $from], ['status' => $locked->status->value, 'publish_at' => $locked->publish_at?->toIso8601String()], $actor);

            return $locked;
        });
    }

    private function assertPublishable(Product $product): void
    {
        $default = (string) config('app.default_locale');

        if (! DB::table('product_translations')->where('product_id', $product->id)->where('locale', $default)->where('name', '!=', '')->exists()) {
            throw CatalogException::notPublishable("a {$default} name is required");
        }

        if (! DB::table('product_variants')->where('product_id', $product->id)->whereNull('deleted_at')->where('status', 'active')->exists()) {
            throw CatalogException::notPublishable('it needs at least one active variant');
        }

        $variantIds = array_values(array_map('intval', DB::table('product_variants')->where('product_id', $product->id)->whereNull('deleted_at')->where('status', 'active')->pluck('id')->all()));
        if (! $this->prices->allHaveRegularPrice($variantIds)) {
            throw CatalogException::notPublishable('every active variant needs a price');
        }

        if (! DB::table('product_categories')->where('product_id', $product->id)->exists()) {
            throw CatalogException::notPublishable('it needs at least one category');
        }
    }
}
