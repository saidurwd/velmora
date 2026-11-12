<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Resources;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

/** The `meta` and `links` of a page-based list (API_ARCHITECTURE §1). Links keep the caller's own query and change only `page`. */
final class PageEnvelope
{
    /**
     * @param  LengthAwarePaginator<int, mixed>  $page
     * @return array{page: int, per_page: int, total: int, last_page: int}
     */
    public static function meta(LengthAwarePaginator $page): array
    {
        return ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => max(1, $page->lastPage())];
    }

    /**
     * @param  LengthAwarePaginator<int, mixed>  $page
     * @return array{prev: string|null, next: string|null}
     */
    public static function links(LengthAwarePaginator $page, Request $request): array
    {
        $to = fn (int $n): string => $request->url().'?'.http_build_query(['page' => $n] + $request->query());

        return [
            'prev' => $page->currentPage() > 1 ? $to($page->currentPage() - 1) : null,
            'next' => $page->currentPage() < $page->lastPage() ? $to($page->currentPage() + 1) : null,
        ];
    }
}
