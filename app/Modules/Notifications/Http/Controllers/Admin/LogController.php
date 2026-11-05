<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers\Admin;

use App\Modules\Notifications\Actions\RetryDeadNotification;
use App\Modules\Notifications\Exceptions\NotificationException;
use App\Modules\Notifications\Http\Concerns\ActsAsStaff;
use App\Modules\Notifications\Http\Requests\RetryNotificationRequest;
use App\Modules\Notifications\Services\NotificationReader;
use App\Modules\Notifications\Services\TemplateCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Staff see every message the shop tried to send, whether it arrived, and why not; and send a message that gave up again. No address or text is shown. */
class LogController
{
    use ActsAsStaff;

    public function __construct(private readonly NotificationReader $reader) {}

    public function index(Request $request, TemplateCatalog $catalog): View
    {
        $filters = $request->validate(['status' => ['nullable', 'string', 'max:10'], 'template' => ['nullable', 'string', 'max:80'], 'channel' => ['nullable', 'string', 'max:5'], 'from' => ['nullable', 'string', 'max:10'], 'to' => ['nullable', 'string', 'max:10']]);

        return view('notifications::admin.logs', [
            'page' => $this->reader->logs(array_map(fn ($v) => (string) $v, $filters))->withQueryString(), 'filters' => $filters, 'statuses' => NotificationReader::STATUSES, 'codes' => array_keys($catalog->all()),
            'health' => $this->reader->health(), 'can' => $request->user('admin')?->can('notifications.manage') === true,
        ]);
    }

    public function retry(RetryNotificationRequest $request, RetryDeadNotification $retry, string $log): RedirectResponse
    {
        try {
            $retry->handle($this->actor($request), $log, (string) $request->validated('reason'));
        } catch (NotificationException $e) {
            return redirect()->route('admin.notifications.logs')->withErrors(['log' => __('notifications::errors.'.$e->reason)]);
        }

        return redirect()->route('admin.notifications.logs', ['status' => 'dead'])->with('status', __('notifications::admin.retried'));
    }
}
