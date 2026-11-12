<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Http\Controllers\Api;

use App\Modules\Engagement\Actions\SubmitReview;
use App\Modules\Engagement\Actions\WithdrawReview;
use App\Modules\Engagement\Exceptions\EngagementException;
use App\Modules\Engagement\Http\EngagementProblems;
use App\Modules\Engagement\Http\Requests\Api\StoreReviewApiRequest;
use App\Modules\Engagement\Services\ReviewReader;
use App\Modules\Orders\Contracts\ApiOrderAccess;
use App\Modules\Platform\Enums\ErrorCode;
use App\Modules\Platform\Http\ApiProblem;
use App\Modules\Platform\Http\Resources\ApiTypes;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Response as ResponseDoc;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Reviews: anyone may read the published ones; only a verified buyer may write one, once per item bought, and nothing shows until the shop approves it. */
class ReviewController
{
    private const MINE = 'array{id: string, name: string, rating: int, title: string|null, body: string, status: string, reason: string|null, created_at: string}';

    /**
     * Reviews of a product
     *
     * Of the product a variant belongs to: send the product's `variant.id` as `variant`. Published reviews only, newest first, 10 a page (`page`); `stats` counts them all.
     *
     * @unauthenticated
     */
    #[ResponseDoc(status: 200, type: 'array{data: array{stats: array{count: int, average: float, stars: array<string, int>}, reviews: list<array{id: string, rating: int, title: string|null, body: string, locale: string, author: string|null, created_at: string}>}, meta: '.ApiTypes::PAGE.', links: '.ApiTypes::LINKS.'}')]
    public function forProduct(Request $request, ReviewReader $reader): JsonResponse
    {
        $request->validate(['variant' => ['required', 'string', 'size:26'], 'page' => ['nullable', 'integer', 'min:1', 'max:10000']]);
        $request->merge(['reviews_page' => (int) $request->query('page', 1)]); // the reader pages by this name
        $found = $reader->forVariant((string) $request->query('variant'), app()->getLocale()) ?? throw new ApiProblem(ErrorCode::ProductNotFound);

        $page = $found['page'];

        return response()->json([
            'data' => [
                'stats' => ['count' => $found['stats']['count'], 'average' => $found['stats']['average'], 'stars' => (object) $found['stats']['stars']],
                'reviews' => array_map(fn ($r): array => ['id' => (string) $r->public_id, 'rating' => (int) $r->rating, 'title' => $r->title, 'body' => (string) $r->body, 'locale' => (string) $r->locale, 'author' => $r->author_name, 'created_at' => CarbonImmutable::parse((string) $r->created_at, 'UTC')->toIso8601String()], $page->items()),
            ],
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => max(1, $page->lastPage())],
            'links' => ['prev' => $page->currentPage() > 1 ? $request->url().'?'.http_build_query(['variant' => $request->query('variant'), 'page' => $page->currentPage() - 1]) : null, 'next' => $page->currentPage() < $page->lastPage() ? $request->url().'?'.http_build_query(['variant' => $request->query('variant'), 'page' => $page->currentPage() + 1]) : null],
        ]);
    }

    /**
     * What I can review in an order
     *
     * The lines of my own order I may review now (paid or delivered, not cancelled, soon enough), and whether I already have. Somebody else's order is 404.
     */
    #[ResponseDoc(status: 200, type: 'array{data: list<array{variant_id: string, name: string, reviewed: bool}>}')]
    public function reviewable(Request $request, string $id, ApiOrderAccess $access, ReviewReader $reader): JsonResponse
    {
        $grant = $access->find($request, $id);
        ($grant !== null && $grant->isOwner) || throw new ApiProblem(ErrorCode::NotFound);

        return response()->json(['data' => array_map(fn (array $l): array => ['variant_id' => $l['variant'], 'name' => $l['name'], 'reviewed' => $l['reviewed']], $reader->reviewable((int) $grant->ownerUserId, $grant->publicId, app()->getLocale()))]);
    }

    /**
     * Write a review
     *
     * 1 to 5 stars, an optional headline and 10 to 2,000 characters; no links; the same text twice in a month is refused; 5 an hour. It shows only after the shop approves it. Not a buyer, not yet delivered, too long ago: `review.not_eligible`; already reviewed: `review.duplicate`.
     */
    #[ResponseDoc(status: 201, type: 'array{data: array{id: string, status: string}}')]
    public function store(StoreReviewApiRequest $request, string $id, ApiOrderAccess $access, SubmitReview $submit): JsonResponse
    {
        $grant = $access->find($request, $id);
        ($grant !== null && $grant->isOwner) || throw new ApiProblem(ErrorCode::NotFound);

        try {
            $review = $submit->handle((int) $grant->ownerUserId, $grant->publicId, (string) $request->validated('variant'), (int) $request->validated('rating'), $request->validated('title'), (string) $request->validated('body'));
        } catch (EngagementException $e) {
            throw EngagementProblems::from($e);
        }

        return response()->json(['data' => ['id' => $review, 'status' => 'pending']], 201);
    }

    /**
     * My reviews
     *
     * With their state (`pending`, `published`, `rejected`, `withdrawn`) and, when the shop rejected or removed one, the reason.
     */
    #[ResponseDoc(status: 200, type: 'array{data: list<'.self::MINE.'>}')]
    public function mine(Request $request, ReviewReader $reader): JsonResponse
    {
        return response()->json(['data' => $reader->mine((int) $request->user()?->getAuthIdentifier(), app()->getLocale())]);
    }

    /**
     * Withdraw a review
     *
     * @status 204
     */
    public function withdraw(Request $request, string $id, WithdrawReview $withdraw): Response
    {
        try {
            $withdraw->handle((int) $request->user()?->getAuthIdentifier(), $id);
        } catch (EngagementException $e) {
            throw EngagementProblems::from($e);
        }

        return response()->noContent();
    }
}
