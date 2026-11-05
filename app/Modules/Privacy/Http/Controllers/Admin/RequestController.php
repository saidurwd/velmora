<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Http\Controllers\Admin;

use App\Modules\Privacy\Actions\DecideErasure;
use App\Modules\Privacy\Exceptions\PrivacyException;
use App\Modules\Privacy\Http\Concerns\ActsAsStaff;
use App\Modules\Privacy\Http\Requests\DecideErasureRequest;
use App\Modules\Privacy\Services\PrivacyReader;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Staff see customers' privacy requests and decide erasure requests. Looking = privacy.view; deciding = privacy.manage (set on the routes). */
class RequestController
{
    use ActsAsStaff;

    public function __construct(private readonly PrivacyReader $reader) {}

    public function index(Request $request): View
    {
        $filters = $request->validate(['type' => ['nullable', 'in:export,erasure'], 'status' => ['nullable', 'in:'.implode(',', PrivacyReader::STATUSES)]]);

        return view('privacy::admin.index', ['page' => $this->reader->queue(array_map(fn ($v) => (string) $v, $filters))->withQueryString(), 'filters' => $filters, 'statuses' => PrivacyReader::STATUSES]);
    }

    public function show(Request $request, string $publicId): View
    {
        $detail = $this->reader->detail($publicId);
        abort_if($detail === null, 404);

        return view('privacy::admin.show', $detail + ['can' => $request->user('admin')?->can('privacy.manage') === true]);
    }

    public function approve(DecideErasureRequest $request, DecideErasure $decide, string $publicId): RedirectResponse
    {
        try {
            $decide->approve($this->actor($request), $publicId, $request->validated('reason'));
        } catch (PrivacyException $e) {
            return redirect()->route('admin.privacy.show', ['publicId' => $publicId])->withErrors(['request' => __('privacy::errors.'.$e->reason)]);
        }

        return redirect()->route('admin.privacy.show', ['publicId' => $publicId])->with('status', __('privacy::admin.approved'));
    }

    public function reject(DecideErasureRequest $request, DecideErasure $decide, string $publicId): RedirectResponse
    {
        try {
            $decide->reject($this->actor($request), $publicId, (string) $request->validated('reason'));
        } catch (PrivacyException $e) {
            return redirect()->route('admin.privacy.show', ['publicId' => $publicId])->withErrors(['request' => __('privacy::errors.'.$e->reason)]);
        }

        return redirect()->route('admin.privacy.show', ['publicId' => $publicId])->with('status', __('privacy::admin.rejected'));
    }
}
