<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Http\Controllers\Web;

use App\Modules\Engagement\Actions\SubmitReview;
use App\Modules\Engagement\Actions\WithdrawReview;
use App\Modules\Engagement\Exceptions\EngagementException;
use App\Modules\Engagement\Http\Requests\StoreReviewRequest;
use App\Modules\Engagement\Services\ReviewReader;
use App\Modules\Orders\Contracts\PurchasedLines;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** A signed-in customer writes a review of something they bought, sees their own reviews, and withdraws one. The customer is always the one in the session. */
class ReviewController
{
    private function userId(Request $request): int
    {
        return (int) $request->user('web')?->getAuthIdentifier();
    }

    public function create(Request $request, ReviewReader $reader, PurchasedLines $lines, string $order, string $variant): View|RedirectResponse
    {
        $line = collect($reader->reviewable($this->userId($request), $order, app()->getLocale()))->firstWhere('variant', $variant);
        if ($line === null) {
            abort_if($lines->forOrder($this->userId($request), $order) === [], 404);   // someone else's order is not found; our own order's other lines just cannot be reviewed

            return redirect()->route('account.orders.show', $order)->withErrors(['review' => __('engagement::errors.not_eligible')]);
        }
        if ($line['reviewed']) {
            return redirect()->route('account.reviews');
        }

        return view('engagement::account.review-form', ['order' => $order, 'variant' => $variant, 'name' => $line['name']]);
    }

    public function store(StoreReviewRequest $request, SubmitReview $submit, string $order, string $variant): RedirectResponse
    {
        try {
            $submit->handle($this->userId($request), $order, $variant, (int) $request->validated('rating'), $request->validated('title'), (string) $request->validated('body'));
        } catch (EngagementException $e) {
            abort_if($e->reason === 'not_found', 404);

            return back()->withInput()->withErrors(['review' => __('engagement::errors.'.$e->reason, $e->params)]);
        }

        return redirect()->route('account.reviews')->with('status', __('engagement::reviews.sent_status'));
    }

    public function mine(Request $request, ReviewReader $reader): View
    {
        return view('engagement::account.reviews', ['reviews' => $reader->mine($this->userId($request), app()->getLocale())]);
    }

    public function withdraw(Request $request, WithdrawReview $withdraw, string $review): RedirectResponse
    {
        try {
            $withdraw->handle($this->userId($request), $review);
        } catch (EngagementException $e) {
            abort_if($e->reason === 'not_found', 404);

            return back()->withErrors(['review' => __('engagement::errors.'.$e->reason)]);
        }

        return back()->with('status', __('engagement::reviews.withdrawn_status'));
    }
}
