<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Http\Controllers\Admin;

use App\Modules\Bookings\Actions\CancelBooking;
use App\Modules\Bookings\Actions\CompleteBooking;
use App\Modules\Bookings\Actions\MarkNoShow;
use App\Modules\Bookings\Actions\ReassignBooking;
use App\Modules\Bookings\Actions\RescheduleBooking;
use App\Modules\Bookings\Exceptions\BookingException;
use App\Modules\Bookings\Http\Concerns\ActsAsStaff;
use App\Modules\Bookings\Http\Requests\CancelBookingRequest;
use App\Modules\Bookings\Http\Requests\MoveBookingRequest;
use App\Modules\Bookings\Http\Requests\NoShowRequest;
use App\Modules\Bookings\Http\Requests\ReassignBookingRequest;
use App\Modules\Bookings\Services\StaffBookings;
use App\Modules\Platform\Contracts\Clock;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Staff: the bookings list, one booking's story and the few things staff may do to it, and the calendar. Every change goes through the same Action the customer pages use. */
class BookingController
{
    use ActsAsStaff;

    public function __construct(private readonly StaffBookings $bookings) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'max:20'], 'from' => ['nullable', 'string', 'max:10'], 'to' => ['nullable', 'string', 'max:10'], 'resource' => ['nullable', 'string', 'max:26'],
            'sku' => ['nullable', 'string', 'max:64'], 'order' => ['nullable', 'string', 'max:30'],
        ]);

        return view('bookings::admin.bookings.index', [
            'page' => $this->bookings->list(array_map(fn ($v) => (string) $v, $filters))->withQueryString(), 'filters' => $filters, 'statuses' => StaffBookings::STATUSES,
            'resources' => DB::table('service_resources')->orderBy('name')->get(['public_id', 'name']),
        ]);
    }

    public function show(Request $request, string $booking): View
    {
        $date = $request->query('date');
        $detail = $this->bookings->detail($booking, is_string($date) ? $date : null) ?? abort(404);

        return view('bookings::admin.bookings.show', ['b' => $detail, 'can' => $request->user('admin')?->can('bookings.manage') === true, 'canSeePii' => $request->user('admin')?->can('customers.view_pii') === true]);
    }

    public function calendar(Request $request, Clock $clock): View
    {
        $zone = (string) config('bookings.timezone');
        $view = $request->query('view') === 'week' ? 'week' : 'day';
        $date = (string) $request->query('date', $clock->now()->setTimezone($zone)->format('Y-m-d'));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
            $date = $clock->now()->setTimezone($zone)->format('Y-m-d');
        }
        $start = $date;
        if ($view === 'week') {
            $start = $clock->now()->setTimezone($zone)->parse($date, $zone)->startOfWeek()->format('Y-m-d');
        }
        $resource = $request->query('resource');

        return view('bookings::admin.bookings.calendar', [
            'cal' => $this->bookings->calendar($start, $view === 'week' ? 7 : 1, is_string($resource) ? $resource : null), 'view' => $view, 'date' => $date, 'start' => $start,
            'resource' => is_string($resource) ? $resource : '', 'allResources' => DB::table('service_resources')->where('is_active', true)->orderBy('name')->get(['public_id', 'name']),
        ]);
    }

    public function cancel(CancelBookingRequest $request, CancelBooking $cancel, string $booking): RedirectResponse
    {
        return $this->attempt($booking, fn () => $cancel->handle($this->actor($request), $booking, (string) $request->validated('reason'), (bool) $request->validated('waive')), 'bookings::admin.done.cancelled');
    }

    public function noShow(NoShowRequest $request, MarkNoShow $noShow, string $booking): RedirectResponse
    {
        return $this->attempt($booking, fn () => $noShow->handle($this->actor($request), $booking, $request->validated('reason'), (bool) $request->validated('waive')), 'bookings::admin.done.no_show');
    }

    public function complete(Request $request, CompleteBooking $complete, string $booking): RedirectResponse
    {
        return $this->attempt($booking, fn () => $complete->handle($this->actor($request), $booking), 'bookings::admin.done.completed');
    }

    public function move(MoveBookingRequest $request, RescheduleBooking $move, string $booking): RedirectResponse
    {
        return $this->attempt($booking, fn () => $move->handle($this->actor($request), $booking, (string) $request->validated('start'), true, (string) $request->validated('reason')), 'bookings::admin.done.moved');
    }

    public function reassign(ReassignBookingRequest $request, ReassignBooking $reassign, string $booking): RedirectResponse
    {
        return $this->attempt($booking, fn () => $reassign->handle($this->actor($request), $booking, (string) $request->validated('resource')), 'bookings::admin.done.reassigned');
    }

    private function attempt(string $booking, callable $do, string $success): RedirectResponse
    {
        $back = route('admin.bookings.show', ['booking' => $booking]);
        try {
            $do();
        } catch (BookingException $e) {
            return redirect($back)->withErrors(['booking' => __('bookings::errors.'.$e->reason, $e->params)]);
        }

        return redirect($back)->with('status', __($success));
    }
}
