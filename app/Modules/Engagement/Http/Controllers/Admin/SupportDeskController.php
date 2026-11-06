<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Http\Controllers\Admin;

use App\Modules\Engagement\Actions\AssignSupportTicket;
use App\Modules\Engagement\Actions\ChangeSupportTicketStatus;
use App\Modules\Engagement\Actions\OpenSupportTicket;
use App\Modules\Engagement\Actions\StaffReplyToSupportTicket;
use App\Modules\Engagement\Exceptions\EngagementException;
use App\Modules\Engagement\Http\Concerns\ActsAsStaff;
use App\Modules\Engagement\Http\Controllers\Web\SupportFileResponse;
use App\Modules\Engagement\Http\Requests\SupportMessageRequest;
use App\Modules\Engagement\Services\CannedReplies;
use App\Modules\Engagement\Services\SupportAttachmentAccess;
use App\Modules\Engagement\Services\SupportReader;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The support desk. Looking needs support.view, answering and notes support.reply, closing and giving tickets to others support.manage (set on the routes). */
class SupportDeskController
{
    use ActsAsStaff;

    public function index(Request $request, SupportReader $reader): View
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:active,open,answered,closed'], 'assignee' => ['nullable', 'in:mine,none'], 'category' => ['nullable', 'in:'.implode(',', OpenSupportTicket::CATEGORIES)],
            'overdue' => ['nullable', 'in:1'], 'q' => ['nullable', 'string', 'max:12'],
        ]);

        return view('engagement::admin.support.index', ['page' => $reader->queue(array_map(fn ($v) => (string) $v, $filters), (int) $this->actor($request)->id)->withQueryString(), 'filters' => $filters, 'categories' => OpenSupportTicket::CATEGORIES]);
    }

    public function show(Request $request, SupportReader $reader, CannedReplies $canned, string $ticket): View
    {
        $thread = $reader->staffThread($ticket);
        abort_if($thread === null, 404);
        $locale = (string) $thread['ticket']->locale;
        $prefill = ($pick = (string) $request->query('canned', '')) === '' ? null : $canned->text($pick, $locale, $thread['customer']?->name, $thread['order']['number'] ?? null);
        $me = $request->user('admin');

        return view('engagement::admin.support.show', $thread + [
            'canned' => $canned->pick($locale), 'prefill' => $prefill, 'chosen' => $prefill === null ? '' : $pick, 'canReply' => $me?->can('support.reply') === true, 'canManage' => $me?->can('support.manage') === true, 'myId' => (int) $this->actor($request)->id,
        ]);
    }

    public function reply(SupportMessageRequest $request, StaffReplyToSupportTicket $reply, string $ticket): RedirectResponse
    {
        try {
            $reply->handle($this->actor($request), $ticket, (string) $request->validated('body'), $request->boolean('internal'), array_values(array_filter((array) $request->file('files'), fn ($f) => $f instanceof UploadedFile)));
        } catch (EngagementException $e) {
            abort_if($e->reason === 'not_found', 404);

            return back()->withInput()->withErrors(['support' => __('engagement::support.errors.'.$e->reason, $e->params)]);
        }

        return back()->with('status', __($request->boolean('internal') ? 'engagement::support.note_saved' : 'engagement::support.answer_sent'));
    }

    public function assign(Request $request, AssignSupportTicket $assign, string $ticket): RedirectResponse
    {
        $data = $request->validate(['assignee' => ['nullable', 'integer', 'min:1']]);
        try {
            $assign->handle($this->actor($request), $ticket, isset($data['assignee']) ? (int) $data['assignee'] : null, $request->user('admin')?->can('support.manage') === true);
        } catch (EngagementException $e) {
            abort_if($e->reason === 'not_found', 404);

            return back()->withErrors(['support' => __('engagement::support.errors.'.$e->reason)]);
        }

        return back()->with('status', __('engagement::support.assigned'));
    }

    public function close(Request $request, ChangeSupportTicketStatus $status, string $ticket): RedirectResponse
    {
        return $this->change($request, fn () => $status->close($this->actor($request), $ticket), 'engagement::support.closed_staff');
    }

    public function reopen(Request $request, ChangeSupportTicketStatus $status, string $ticket): RedirectResponse
    {
        return $this->change($request, fn () => $status->reopen($this->actor($request), $ticket), 'engagement::support.reopened');
    }

    public function file(SupportAttachmentAccess $access, string $file): StreamedResponse
    {
        $found = $access->find($file, null, true);
        abort_if($found === null, 404);

        return SupportFileResponse::stream($found);
    }

    private function change(Request $request, \Closure $do, string $message): RedirectResponse
    {
        try {
            $do();
        } catch (EngagementException $e) {
            abort_if($e->reason === 'not_found', 404);

            return back()->withErrors(['support' => __('engagement::support.errors.'.$e->reason)]);
        }

        return back()->with('status', __($message));
    }
}
