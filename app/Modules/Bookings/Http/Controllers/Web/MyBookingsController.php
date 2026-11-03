<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Http\Controllers\Web;

use App\Modules\Bookings\Http\Concerns\ManagesOwnBookings;
use App\Modules\Bookings\Services\MyBookings;
use App\Modules\Orders\Contracts\CustomerOrders;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** "My bookings" for a signed-in customer: their own bookings only (Orders says which orders are theirs); anybody else's booking is simply "not found". */
class MyBookingsController
{
    use ManagesOwnBookings;

    public function __construct(private readonly MyBookings $bookings, private readonly CustomerOrders $orders) {}

    public function index(Request $request): View
    {
        return view('bookings::mine.index', ['rows' => $this->bookings->forOrders($this->orderIds($request), app()->getLocale()), 'guest' => false, 'showRoute' => 'bookings.mine.show', 'showParams' => []]);
    }

    public function show(Request $request, string $booking): View
    {
        $row = $this->bookings->find($this->orderIds($request), $booking, app()->getLocale()) ?? abort(404);
        $locale = app()->getLocale();
        $urls = ['self' => route('bookings.mine.show', ['locale' => $locale, 'booking' => $booking]), 'list' => route('bookings.mine', ['locale' => $locale]),
            'cancel' => route('bookings.mine.cancel', ['locale' => $locale, 'booking' => $booking]), 'move' => route('bookings.mine.move', ['locale' => $locale, 'booking' => $booking]),
            'complete' => route('bookings.mine.complete', ['locale' => $locale, 'booking' => $booking])];

        return view('bookings::mine.show', ['row' => $row, 'guest' => false, 'urls' => $urls] + $this->moveChoices($request, $row));
    }

    public function cancel(Request $request, string $booking): RedirectResponse
    {
        return $this->doCancel($request, $this->actor($request), $booking, route('bookings.mine.show', ['locale' => app()->getLocale(), 'booking' => $booking]));
    }

    public function move(Request $request, string $booking): RedirectResponse
    {
        return $this->doMove($request, $this->actor($request), $booking, route('bookings.mine.show', ['locale' => app()->getLocale(), 'booking' => $booking]));
    }

    public function complete(Request $request, string $booking): RedirectResponse
    {
        return $this->doComplete($this->actor($request), $booking, route('bookings.mine.show', ['locale' => app()->getLocale(), 'booking' => $booking]));
    }

    private function actor(Request $request): Actor
    {
        return new Actor(ActorType::Customer, (int) $request->user('web')?->getAuthIdentifier());
    }

    /** @return list<int> */
    private function orderIds(Request $request): array
    {
        return $this->orders->orderIdsOwnedBy((int) $request->user('web')?->getAuthIdentifier());
    }
}
