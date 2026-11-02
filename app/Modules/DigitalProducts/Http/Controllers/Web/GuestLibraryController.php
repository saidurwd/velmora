<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Http\Controllers\Web;

use App\Modules\DigitalProducts\Actions\ConfirmGuestDownloadAccess;
use App\Modules\DigitalProducts\Actions\DownloadDigitalFile;
use App\Modules\DigitalProducts\Exceptions\DigitalException;
use App\Modules\DigitalProducts\Http\Concerns\RedirectsToDownload;
use App\Modules\DigitalProducts\Models\Entitlement;
use App\Modules\DigitalProducts\Services\GuestDownloadAccess;
use App\Modules\DigitalProducts\Services\LibraryReader;
use App\Modules\IdentityAccess\Enums\OtpIssueStatus;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * A guest's downloads: reached only through a link WE signed (it expires), then a code sent to the order's phone, then a short session for that one order.
 * Every answer to a wrong or missing proof reads the same.
 */
class GuestLibraryController
{
    use RedirectsToDownload;

    public function show(Request $request, ConfirmGuestDownloadAccess $confirm, GuestDownloadAccess $access, LibraryReader $reader, string $order): View
    {
        $found = $confirm->order($order) ?? abort(404);

        if (! $access->has($order)) {
            return view('digital-products::guest.code', [
                'number' => $found['number'], 'phoneEnd' => mb_substr($found['phone'], -3), 'sendUrl' => $this->signed('digital.guest.code', $request, $order), 'verifyUrl' => $this->signed('digital.guest.verify', $request, $order),
            ]);
        }

        $rows = array_map(fn (array $r): array => $r + ['action' => route('digital.guest.download', ['locale' => app()->getLocale(), 'order' => $order, 'entitlement' => $r['id']])], $reader->forOrders([$found['id']], app()->getLocale()));

        return view('digital-products::library.index', ['rows' => $rows])->with('guest', true)->with('number', $found['number']);
    }

    public function sendCode(Request $request, ConfirmGuestDownloadAccess $confirm, string $order): RedirectResponse
    {
        $status = $confirm->send($order, $request->ip(), app()->getLocale()) ?? abort(404);

        return redirect($this->signed('digital.guest.library', $request, $order))->with($status === OtpIssueStatus::Sent ? 'code_status' : 'code_problem', __('digital-products::library.code.'.($status === OtpIssueStatus::Sent ? 'sent' : 'send_'.$status->value)));
    }

    /** Checks the code. Every failure reads the same, so the page never says which part was wrong. */
    public function verify(Request $request, ConfirmGuestDownloadAccess $confirm, string $order): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);

        if (! $confirm->verify($order, $data['code'])) {
            return redirect($this->signed('digital.guest.library', $request, $order))->withErrors(['code' => __('digital-products::library.code.invalid')]);
        }

        return redirect($this->signed('digital.guest.library', $request, $order));
    }

    public function download(Request $request, ConfirmGuestDownloadAccess $confirm, GuestDownloadAccess $access, DownloadDigitalFile $download, string $order, string $entitlement): RedirectResponse
    {
        $found = $confirm->order($order) ?? abort(404);
        abort_unless($access->has($order), 403);
        $right = Entitlement::query()->where('public_id', $entitlement)->firstOrFail();

        if ($right->order_id !== $found['id']) {
            $download->logNotOwner($right, null, $request->ip(), $request->userAgent());

            abort(404);
        }

        try {
            return $this->toDownload($download->handle($right, null, $request->ip(), $request->userAgent()));
        } catch (DigitalException $e) {
            return back()->withErrors(['download' => __('digital-products::library.errors.'.$e->reason)]);
        }
    }

    /** The same signed link's life: a link made from this page expires when the page's own link does. */
    private function signed(string $route, Request $request, string $order): string
    {
        return URL::temporarySignedRoute($route, CarbonImmutable::createFromTimestamp((int) $request->query('expires')), ['locale' => app()->getLocale(), 'order' => $order]);
    }
}
