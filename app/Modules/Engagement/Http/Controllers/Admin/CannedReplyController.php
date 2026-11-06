<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Http\Controllers\Admin;

use App\Modules\Engagement\Actions\SaveCannedReply;
use App\Modules\Engagement\Exceptions\EngagementException;
use App\Modules\Engagement\Http\Concerns\ActsAsStaff;
use App\Modules\Engagement\Services\CannedReplies;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** The ready-made answers (support.manage). */
class CannedReplyController
{
    use ActsAsStaff;

    public function index(CannedReplies $canned): View
    {
        return view('engagement::admin.support.canned-index', ['replies' => $canned->all()]);
    }

    public function create(): View
    {
        return view('engagement::admin.support.canned-form', ['reply' => null, 'locales' => (array) config('app.supported_locales')]);
    }

    public function edit(CannedReplies $canned, string $reply): View
    {
        $found = $canned->one($reply);
        abort_if($found === null, 404);

        return view('engagement::admin.support.canned-form', ['reply' => $found, 'locales' => (array) config('app.supported_locales')]);
    }

    public function save(Request $request, SaveCannedReply $save, ?string $reply = null): RedirectResponse
    {
        $data = $request->validate(['active' => ['sometimes', 'boolean'], 'sort' => ['nullable', 'integer', 'min:0', 'max:65535'], 'texts' => ['required', 'array'], 'texts.*.title' => ['nullable', 'string', 'max:80'], 'texts.*.body' => ['nullable', 'string', 'max:'.SaveCannedReply::BODY_MAX]]);
        try {
            $save->handle($this->actor($request), $reply, $request->boolean('active'), (int) ($data['sort'] ?? 0), $data['texts']);
        } catch (EngagementException $e) {
            abort_if($e->reason === 'not_found', 404);

            return back()->withInput()->withErrors(['canned' => __('engagement::support.errors.'.$e->reason)]);
        }

        return redirect()->route('admin.support.canned.index')->with('status', __('engagement::support.canned_saved'));
    }

    public function destroy(Request $request, SaveCannedReply $save, string $reply): RedirectResponse
    {
        try {
            $save->delete($this->actor($request), $reply);
        } catch (EngagementException) {
            abort(404);
        }

        return redirect()->route('admin.support.canned.index')->with('status', __('engagement::support.canned_deleted'));
    }
}
