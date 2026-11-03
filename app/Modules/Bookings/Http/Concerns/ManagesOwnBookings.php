<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Http\Concerns;

use App\Modules\Bookings\Actions\CancelBooking;
use App\Modules\Bookings\Actions\CompleteBooking;
use App\Modules\Bookings\Actions\RescheduleBooking;
use App\Modules\Bookings\Exceptions\BookingException;
use App\Modules\Bookings\Models\ServicePackage;
use App\Modules\Bookings\Services\SlotSearch;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use DateTimeImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** What one booking page needs, whether the visitor is signed in or a guest who proved the order's phone: the free times to move to, and the three things they may do. */
trait ManagesOwnBookings
{
    /**
     * @param  array<string, mixed>  $row
     * @return array{date: string, min: string, max: string, slots: list<mixed>, problem: string|null}
     */
    private function moveChoices(Request $request, array $row): array
    {
        $zone = (string) config('bookings.timezone');
        $today = app(Clock::class)->now()->setTimezone($zone);
        $date = (string) $request->query('date', $row['starts_at']->setTimezone($zone)->format('Y-m-d'));
        $valid = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $problem = null;
        if ($valid === false || $valid->format('Y-m-d') !== $date || $date < $today->format('Y-m-d')) {
            $date = $row['starts_at']->setTimezone($zone)->format('Y-m-d');
            $problem = 'date_invalid';
        }

        $slots = [];
        if ($row['can_move']) {
            $package = ServicePackage::query()->findOrFail((int) $row['package_id']);
            $resource = (string) DB::table('service_resources')->where('id', $row['resource_id'])->value('public_id');
            $slots = array_slice(app(SlotSearch::class)->forPackage($package, $date, $date, $resource, $row['booking_id']), 0, 60);
        }

        return ['date' => $date, 'min' => $today->format('Y-m-d'), 'max' => $today->addDays(366)->format('Y-m-d'), 'slots' => $slots, 'problem' => $problem];
    }

    private function doCancel(Request $request, Actor $actor, string $booking, string $back): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return $this->attempt($back, fn () => app(CancelBooking::class)->handle($actor, $booking, $data['reason'] ?? null), 'bookings::mine.cancelled');
    }

    private function doMove(Request $request, Actor $actor, string $booking, string $back): RedirectResponse
    {
        $data = $request->validate(['start' => ['required', 'string', 'max:40']]);

        return $this->attempt($back, fn () => app(RescheduleBooking::class)->handle($actor, $booking, $data['start']), 'bookings::mine.moved');
    }

    private function doComplete(Actor $actor, string $booking, string $back): RedirectResponse
    {
        return $this->attempt($back, fn () => app(CompleteBooking::class)->handle($actor, $booking), 'bookings::mine.completed');
    }

    private function attempt(string $back, callable $do, string $success): RedirectResponse
    {
        try {
            $do();
        } catch (BookingException $e) {
            return redirect($back)->withErrors(['booking' => __('bookings::errors.'.$e->reason, $e->params)]);
        }

        return redirect($back)->with('status', __($success));
    }
}
