<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Http\Controllers\Admin;

use App\Modules\Engagement\Actions\ModerateReview;
use App\Modules\Engagement\Exceptions\EngagementException;
use App\Modules\Engagement\Http\Concerns\ActsAsStaff;
use App\Modules\Engagement\Http\Requests\ModerationRequest;
use App\Modules\Engagement\Services\ReviewReader;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** The review moderation queue. Every action needs `reviews.moderate` (set on the routes). */
class ReviewQueueController
{
    use ActsAsStaff;

    public function index(Request $request, ReviewReader $reader): View
    {
        $filters = $request->validate(['status' => ['nullable', 'in:pending,published,rejected,withdrawn']]);

        return view('engagement::admin.reviews', ['page' => $reader->queue(array_map(fn ($v) => (string) $v, $filters), app()->getLocale())->withQueryString(), 'filters' => $filters]);
    }

    public function approve(Request $request, ModerateReview $moderate, string $review): RedirectResponse
    {
        try {
            $moderate->approve($this->actor($request), $review);
        } catch (EngagementException $e) {
            return back()->withErrors(['review' => __('engagement::errors.'.$e->reason)]);
        }

        return back()->with('status', __('engagement::reviews.approved_status'));
    }

    public function reject(ModerationRequest $request, ModerateReview $moderate, string $review): RedirectResponse
    {
        try {
            $moderate->reject($this->actor($request), $review, (string) $request->validated('reason'));
        } catch (EngagementException $e) {
            return back()->withErrors(['review' => __('engagement::errors.'.$e->reason)]);
        }

        return back()->with('status', __('engagement::reviews.rejected_status'));
    }
}
