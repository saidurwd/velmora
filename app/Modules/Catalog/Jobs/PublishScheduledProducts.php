<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Jobs;

use App\Modules\Catalog\Actions\TransitionProduct;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Exceptions\CatalogException;
use App\Modules\Catalog\Models\Product;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Publishes drafts whose scheduled time has arrived. A product that became unpublishable is un-scheduled and audited, not retried forever. */
class PublishScheduledProducts implements ShouldQueue
{
    use Queueable;

    public function handle(TransitionProduct $transition, Clock $clock, AuditLogger $audit): void
    {
        $system = Actor::system('scheduler');

        Product::query()->where('status', ProductStatus::Draft->value)->whereNotNull('publish_at')
            ->where('publish_at', '<=', $clock->now()->format('Y-m-d H:i:s.u'))->orderBy('id')->each(function (Product $product) use ($transition, $audit, $system): void {
                try {
                    $transition->publish($system, $product);
                } catch (CatalogException $e) {
                    $product->forceFill(['publish_at' => null])->save();
                    $audit->record('product.schedule_failed', $product, after: ['reason' => $e->getMessage()], actor: $system);
                }
            });
    }
}
