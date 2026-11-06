<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Services;

use App\Modules\Catalog\Contracts\PurchasableVariants;
use App\Modules\Orders\Contracts\PurchasedLines;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/** What the review pages show: a product's published reviews, a customer's own, the staff queue. Names are fetched in one query for a whole page. */
final class ReviewReader
{
    public const PER_PAGE = 10;

    public function __construct(private readonly RatingStats $stats, private readonly PurchasableVariants $variants, private readonly PurchasedLines $lines) {}

    /** @return array{stats: array{count: int, average: float, stars: array<int, int>}, page: LengthAwarePaginator<int, \stdClass>} */
    public function forProduct(int $productId): array
    {
        return [
            'stats' => $this->stats->forProduct($productId),
            'page' => DB::table('reviews')->where('product_id', $productId)->where('status', 'published')->orderByDesc('id')   // index (product_id, status, id)
                ->paginate(self::PER_PAGE, ['public_id', 'rating', 'title', 'body', 'locale', 'author_name', 'created_at'], 'reviews_page'),
        ];
    }

    /**
     * The reviews of the product a variant belongs to (for the product page, which knows only its variant).
     *
     * @return array{stats: array{count: int, average: float, stars: array<int, int>}, page: LengthAwarePaginator<int, \stdClass>}|null
     */
    public function forVariant(string $variantPublicId, string $locale): ?array
    {
        $id = $this->variants->idByPublicId($variantPublicId);
        $productId = $id === null ? 0 : ($this->variants->details([$id], $locale)[$id]->productId ?? 0);

        return $productId > 0 ? $this->forProduct($productId) : null;
    }

    /** @return list<array{id: string, name: string, rating: int, title: string|null, body: string, status: string, reason: string|null, created_at: string}> */
    public function mine(int $userId, string $locale): array
    {
        $rows = DB::table('reviews')->where('user_id', $userId)->orderByDesc('id')->limit(100)->get();   // index (user_id, id)
        $names = $this->names($rows->pluck('variant_id')->all(), $locale);

        return array_values($rows->map(fn ($r) => ['id' => (string) $r->public_id, 'name' => $names[(int) $r->variant_id] ?? '', 'rating' => (int) $r->rating, 'title' => $r->title, 'body' => (string) $r->body, 'status' => (string) $r->status, 'reason' => $r->rejection_reason, 'created_at' => (string) $r->created_at])->all());
    }

    /**
     * @param  array<string, string>  $filters  status
     * @return LengthAwarePaginator<int, \stdClass>
     */
    public function queue(array $filters, string $locale): LengthAwarePaginator
    {
        $page = DB::table('reviews')->where('status', ($filters['status'] ?? '') === '' ? 'pending' : $filters['status'])->orderBy('id')->paginate(25);   // index (status, id)
        $names = $this->names(array_map(fn ($r) => $r->variant_id, $page->items()), $locale);
        $page->through(function ($row) use ($names) {
            $row->product_name = $names[(int) $row->variant_id] ?? '';

            return $row;
        });

        return $page;
    }

    /** @return list<array{variant: string, name: string, reviewed: bool}> the lines of the customer's order that they may review now, and whether they already have */
    public function reviewable(int $userId, string $orderPublicId, string $locale): array
    {
        $lines = array_values(array_filter($this->lines->forOrder($userId, $orderPublicId), fn ($l) => $l->reviewable));
        if ($lines === []) {
            return [];
        }
        $details = $this->variants->details(array_map(fn ($l) => $l->variantId, $lines), $locale);
        $done = DB::table('reviews')->whereIn('order_item_id', array_map(fn ($l) => $l->orderItemId, $lines))->pluck('order_item_id')->map(fn ($v): int => (int) $v)->all();   // unique order_item_id

        $rows = [];
        foreach ($lines as $line) {
            $d = $details[$line->variantId] ?? null;
            if ($d !== null) {
                $rows[] = ['variant' => $d->publicId, 'name' => trim($d->productName.($d->optionsLabel !== null ? ' ('.$d->optionsLabel.')' : '')), 'reviewed' => in_array($line->orderItemId, $done, true)];
            }
        }

        return $rows;
    }

    /**
     * @param  array<int, int|string>  $variantIds
     * @return array<int, string>
     */
    private function names(array $variantIds, string $locale): array
    {
        $details = $this->variants->details(array_values(array_unique(array_map('intval', $variantIds))), $locale);

        return array_map(fn ($d) => trim($d->productName.($d->optionsLabel !== null ? ' ('.$d->optionsLabel.')' : '')), $details);
    }
}
