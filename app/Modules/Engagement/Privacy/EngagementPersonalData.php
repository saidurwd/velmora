<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Privacy;

use App\Modules\Catalog\Contracts\PurchasableVariants;
use App\Modules\Engagement\Services\RatingStats;
use App\Modules\Privacy\Contracts\PersonalDataEraser;
use App\Modules\Privacy\Contracts\PersonalDataExporter;
use Illuminate\Support\Facades\DB;

/** The customer's wishlist, the alerts they asked for and the reviews they wrote: copied into their data export, and deleted when they are erased (a published review stops counting in the star totals). */
final class EngagementPersonalData implements PersonalDataEraser, PersonalDataExporter
{
    public function __construct(private readonly PurchasableVariants $variants, private readonly RatingStats $stats) {}

    public function section(): string
    {
        return 'wishlist';
    }

    public function export(int $userId): array
    {
        $saved = DB::table('wishlist_items')->where('user_id', $userId)->orderBy('id')->get(['variant_id', 'created_at']);   // unique (user_id, variant_id)
        $alerts = DB::table('stock_alerts')->where('user_id', $userId)->orderBy('id')->get(['variant_id', 'status', 'created_at', 'notified_at']);
        $reviews = DB::table('reviews')->where('user_id', $userId)->orderBy('id')->get(['variant_id', 'rating', 'title', 'body', 'status', 'created_at']);   // index (user_id, id)
        $details = $this->variants->details(array_values($saved->pluck('variant_id')->merge($alerts->pluck('variant_id'))->merge($reviews->pluck('variant_id'))->map(fn ($v): int => (int) $v)->unique()->all()), 'en');
        $name = fn (int $id): string => isset($details[$id]) ? trim($details[$id]->productName.($details[$id]->optionsLabel !== null ? ' ('.$details[$id]->optionsLabel.')' : '')) : '';

        return [
            'saved' => $saved->map(fn ($r) => ['item' => $name((int) $r->variant_id), 'saved_at' => $r->created_at])->all(),
            'reviews' => $reviews->map(fn ($r) => ['item' => $name((int) $r->variant_id), 'rating' => (int) $r->rating, 'title' => $r->title, 'text' => $r->body, 'status' => $r->status, 'written_at' => $r->created_at])->all(),
            'stock_alerts' => $alerts->map(fn ($r) => ['item' => $name((int) $r->variant_id), 'status' => $r->status, 'asked_at' => $r->created_at, 'told_at' => $r->notified_at])->all(),
        ];
    }

    public function blockers(int $userId): array
    {
        return [];
    }

    public function erase(int $userId): void
    {
        DB::table('wishlist_items')->where('user_id', $userId)->delete();
        DB::table('stock_alerts')->where('user_id', $userId)->delete();
        foreach (DB::table('reviews')->where('user_id', $userId)->get(['id', 'product_id', 'rating', 'status']) as $review) {
            if ($review->status === 'published') {
                $this->stats->remove((int) $review->product_id, (int) $review->rating);
            }
            DB::table('reviews')->where('id', $review->id)->delete();
        }
    }
}
