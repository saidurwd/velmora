<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Http\Controllers\Web;

use App\Modules\Engagement\Actions\CloseOwnSupportTicket;
use App\Modules\Engagement\Actions\OpenSupportTicket;
use App\Modules\Engagement\Actions\ReplyToSupportTicket;
use App\Modules\Engagement\Exceptions\EngagementException;
use App\Modules\Engagement\Http\Requests\SupportMessageRequest;
use App\Modules\Engagement\Services\SupportAttachmentAccess;
use App\Modules\Engagement\Services\SupportReader;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** A signed-in customer's conversations with the shop. The customer is always the one in the session; someone else's ticket or file is "not found". */
class SupportController
{
    private function userId(Request $request): int
    {
        return (int) $request->user('web')?->getAuthIdentifier();
    }

    public function index(Request $request, SupportReader $reader): View
    {
        return view('engagement::account.support.index', ['tickets' => $reader->mine($this->userId($request))]);
    }

    public function create(Request $request, SupportReader $reader): View
    {
        return view('engagement::account.support.create', ['orders' => $reader->orderChoices($this->userId($request)), 'categories' => OpenSupportTicket::CATEGORIES, 'order' => (string) $request->query('order', '')]);
    }

    public function store(SupportMessageRequest $request, OpenSupportTicket $open): RedirectResponse
    {
        try {
            $id = $open->handle($this->userId($request), app()->getLocale(), (string) $request->validated('subject'), (string) $request->validated('category'), $request->validated('order'), (string) $request->validated('body'), $this->files($request));
        } catch (EngagementException $e) {
            return back()->withInput()->withErrors(['support' => __('engagement::support.errors.'.$e->reason, $e->params)]);
        }

        return redirect()->route('account.support.show', $id)->with('status', __('engagement::support.opened_status'));
    }

    public function show(Request $request, SupportReader $reader, string $ticket): View
    {
        $thread = $reader->thread($this->userId($request), $ticket);
        abort_if($thread === null, 404);

        return view('engagement::account.support.show', $thread);
    }

    public function reply(SupportMessageRequest $request, ReplyToSupportTicket $reply, string $ticket): RedirectResponse
    {
        try {
            $reply->handle($this->userId($request), $ticket, (string) $request->validated('body'), $this->files($request));
        } catch (EngagementException $e) {
            abort_if($e->reason === 'not_found', 404);

            return back()->withInput()->withErrors(['support' => __('engagement::support.errors.'.$e->reason, $e->params)]);
        }

        return back()->with('status', __('engagement::support.sent_status'));
    }

    public function close(Request $request, CloseOwnSupportTicket $close, string $ticket): RedirectResponse
    {
        try {
            $close->handle($this->userId($request), $ticket);
        } catch (EngagementException $e) {
            abort_if($e->reason === 'not_found', 404);

            return back()->withErrors(['support' => __('engagement::support.errors.'.$e->reason)]);
        }

        return back()->with('status', __('engagement::support.closed_status'));
    }

    public function file(Request $request, SupportAttachmentAccess $access, string $file): StreamedResponse
    {
        $found = $access->find($file, $this->userId($request), false);
        abort_if($found === null, 404);

        return SupportFileResponse::stream($found);
    }

    /** @return list<UploadedFile> */
    private function files(Request $request): array
    {
        return array_values(array_filter((array) $request->file('files'), fn ($f) => $f instanceof UploadedFile));
    }
}
