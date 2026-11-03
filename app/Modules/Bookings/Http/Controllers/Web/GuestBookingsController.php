<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Http\Controllers\Web;

use App\Modules\Bookings\Actions\ConfirmGuestBookingAccess;
use App\Modules\Bookings\Http\Concerns\ManagesOwnBookings;
use App\Modules\Bookings\Services\GuestBookingAccess;
use App\Modules\Bookings\Services\MyBookings;
use App\Modules\IdentityAccess\Enums\OtpIssueStatus;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * A guest's bookings: reached only through a link WE signed (it expires), then a code sent to the order's phone, then a short session for that one order.
 * Every answer to a wrong or missing proof reads the same. Only that order's bookings can be seen or changed.
 */
class GuestBookingsController
{
    use ManagesOwnBookings;

    public function __construct(private readonly MyBookings $bookings, private readonly ConfirmGuestBookingAccess $confirm, private readonly GuestBookingAccess $access) {}

    public function index(Request $request, string $order): View
    {
        $found = $this->confirm->order($order) ?? abort(404);

        if (! $this->access->has($order)) {
            return view('bookings::guest.code', [
                'number' => $found['number'], 'phoneEnd' => mb_substr($found['phone'], -3), 'sendUrl' => $this->signed('bookings.guest.code', $request, $order), 'verifyUrl' => $this->signed('bookings.guest.verify', $request, $order),
            ]);
        }

        return view('bookings::mine.index', ['rows' => $this->bookings->forOrders([$found['id']], app()->getLocale()), 'guest' => true, 'number' => $found['number'], 'showRoute' => 'bookings.guest.show', 'showParams' => ['order' => $order]]);
    }

    public function sendCode(Request $request, string $order): RedirectResponse
    {
        $status = $this->confirm->send($order, $request->ip(), app()->getLocale()) ?? abort(404);

        return redirect($this->signed('bookings.guest', $request, $order))->with($status === OtpIssueStatus::Sent ? 'code_status' : 'code_problem', __('bookings::mine.code.'.($status === OtpIssueStatus::Sent ? 'sent' : 'later')));
    }

    /** Checks the code. Every failure reads the same, so the page never says which part was wrong. */
    public function verify(Request $request, string $order): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);

        if (! $this->confirm->verify($order, $data['code'])) {
            return redirect($this->signed('bookings.guest', $request, $order))->withErrors(['code' => __('bookings::mine.code.invalid')]);
        }

        return redirect($this->signed('bookings.guest', $request, $order));
    }

    public function show(Request $request, string $order, string $booking): View
    {
        $found = $this->confirm->order($order) ?? abort(404);
        abort_unless($this->access->has($order), 403);
        $row = $this->bookings->find([$found['id']], $booking, app()->getLocale()) ?? abort(404);
        $p = ['locale' => app()->getLocale(), 'order' => $order, 'booking' => $booking];
        $urls = ['self' => route('bookings.guest.show', $p), 'list' => null, 'cancel' => route('bookings.guest.cancel', $p), 'move' => route('bookings.guest.move', $p), 'complete' => route('bookings.guest.complete', $p)];

        return view('bookings::mine.show', ['row' => $row, 'guest' => true, 'urls' => $urls] + $this->moveChoices($request, $row));
    }

    public function cancel(Request $request, string $order, string $booking): RedirectResponse
    {
        return $this->doCancel($request, $this->actor($order), $booking, $this->back($order, $booking));
    }

    public function move(Request $request, string $order, string $booking): RedirectResponse
    {
        return $this->doMove($request, $this->actor($order), $booking, $this->back($order, $booking));
    }

    public function complete(string $order, string $booking): RedirectResponse
    {
        return $this->doComplete($this->actor($order), $booking, $this->back($order, $booking));
    }

    /** The guest who proved this order's phone; anything else about the order is checked again by the action. */
    private function actor(string $order): Actor
    {
        $this->confirm->order($order) ?? abort(404);
        abort_unless($this->access->has($order), 403);

        return new Actor(ActorType::Guest, null, $order, 'guest');
    }

    private function back(string $order, string $booking): string
    {
        return route('bookings.guest.show', ['locale' => app()->getLocale(), 'order' => $order, 'booking' => $booking]);
    }

    /** The same signed link's life: a link made from this page expires when the page's own link does. */
    private function signed(string $route, Request $request, string $order): string
    {
        return URL::temporarySignedRoute($route, CarbonImmutable::createFromTimestamp((int) $request->query('expires')), ['locale' => app()->getLocale(), 'order' => $order]);
    }
}
