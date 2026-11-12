<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Http\Controllers\Api;

use App\Modules\Bookings\Actions\CancelBooking;
use App\Modules\Bookings\Actions\CompleteBooking;
use App\Modules\Bookings\Actions\HoldSlot;
use App\Modules\Bookings\Actions\RescheduleBooking;
use App\Modules\Bookings\Exceptions\BookingException;
use App\Modules\Bookings\Models\ServiceDefinition;
use App\Modules\Bookings\Models\ServicePackage;
use App\Modules\Bookings\Services\MyBookings;
use App\Modules\Bookings\Services\SlotSearch;
use App\Modules\Cart\Contracts\ApiCartOwnerResolver;
use App\Modules\Catalog\Contracts\VariantDirectory;
use App\Modules\Orders\Contracts\CustomerOrders;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Enums\ErrorCode;
use App\Modules\Platform\Http\ApiProblem;
use App\Modules\Platform\Http\Resources\ApiTypes;
use App\Modules\Platform\Http\Resources\MoneyData;
use App\Modules\Pricing\Contracts\PriceCatalog;
use DateTimeImmutable;
use Dedoc\Scramble\Attributes\Response as ResponseDoc;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * Booking a time for a service, and managing my bookings. A held time waits in the cart for a few minutes and is confirmed when the order is paid (there is no
 * separate "confirm" call). Managing bookings is for the signed-in customer, whose orders say which bookings are theirs; anyone else's is "not found".
 */
class BookingController
{
    private const BOOKING = 'array{id: string, status: string, service: string, resource: string, starts_at: string, ends_at: string, paid: '.ApiTypes::MONEY.', order: array{id: string, number: string}, upcoming: bool, can_cancel: bool, can_reschedule: bool, can_complete: bool, reschedules_left: int, reschedule_notice_hours: int, if_cancelled: array<string, mixed>|null, ended: array<string, mixed>|null, meeting_url: string|null}';

    /**
     * Free times for a service
     *
     * `sku` is the `variant.sku` of a service. `date` (`YYYY-MM-DD`, Dhaka days, today up to the service's booking horizon; default tomorrow) gives that day's really-free times, up to 60. A time is only a promise once you hold it.
     *
     * @unauthenticated
     */
    #[ResponseDoc(status: 200, type: 'array{data: array{sku: string, date: string, timezone: string, min_date: string, max_date: string, price: '.ApiTypes::MONEY.'|null, slots: list<array{resource: string, resource_name: string, starts_at: string, ends_at: string}>}}')]
    public function slots(Request $request, string $sku, VariantDirectory $variants, SlotSearch $search, PriceCatalog $prices, Clock $clock): JsonResponse
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d'], 'resource' => ['nullable', 'string', 'size:26']]);
        $variant = $variants->findBySku($sku);
        $package = $variant === null || $variant->productType !== 'service' ? null : ServicePackage::query()->where('variant_id', $variant->id)->where('is_active', true)->first();
        $service = $package === null ? null : ServiceDefinition::query()->where('id', $package->service_id)->where('is_active', true)->first();
        ($variant !== null && $package !== null && $service !== null) || throw new ApiProblem(ErrorCode::NotFound);

        $zone = (string) config('bookings.timezone');
        $today = $clock->now()->setTimezone($zone);
        $last = $today->addDays($service->max_horizon_days)->format('Y-m-d');
        $date = (string) $request->query('date', $today->addDay()->format('Y-m-d'));
        $valid = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        ($valid !== false && $valid->format('Y-m-d') === $date && $date >= $today->format('Y-m-d') && $date <= $last) || throw new ApiProblem(ErrorCode::RequestRefused, detail: (string) __('bookings::errors.date_invalid'));

        try {
            $price = MoneyData::from($prices->quote($variant->id, $variant->taxClassCode)->price);
        } catch (Throwable) {
            $price = null;
        }
        $slots = array_slice($search->forPackage($package, $date, $date, $request->query('resource') === null ? null : (string) $request->query('resource')), 0, 60);

        return response()->json(['data' => [
            'sku' => $sku, 'date' => $date, 'timezone' => $zone, 'min_date' => $today->format('Y-m-d'), 'max_date' => $last, 'price' => $price,
            'slots' => array_map(fn ($s): array => ['resource' => $s->resourcePublicId, 'resource_name' => $s->resourceName, 'starts_at' => $s->startsAt->toIso8601String(), 'ends_at' => $s->endsAt->toIso8601String()], $slots),
        ]])->header('Cache-Control', 'no-store');
    }

    /**
     * Hold a time
     *
     * Holds that time for a few minutes and puts the booking in the cart; pay for the cart to confirm it. `resource` and `start` come from a slot (`resource`, `starts_at`). Somebody else was faster: `booking.slot_taken`. A guest gets a `cart_token` (also in the `X-Cart-Token` header) to send on cart and checkout calls. Holding another time for the same service replaces the earlier hold.
     *
     * @unauthenticated
     */
    #[ResponseDoc(status: 201, type: 'array{data: array{id: string, status: string, starts_at: string, ends_at: string, expires_at: string|null, cart_token: string|null}}')]
    public function hold(Request $request, string $sku, HoldSlot $hold, ApiCartOwnerResolver $owners): JsonResponse
    {
        $data = $request->validate(['resource' => ['required', 'string', 'size:26'], 'start' => ['required', 'string', 'max:40']]);
        $owner = $owners->forWrite($request);

        try {
            $booking = $hold->handle($owner, $sku, $data['resource'], $data['start']);
        } catch (BookingException $e) {
            throw $this->problem($e);
        }
        $token = $owner->userId === null ? $owner->guestToken : null;
        $response = response()->json(['data' => [
            'id' => $booking->public_id, 'status' => $booking->status->value, 'starts_at' => $booking->starts_at->toIso8601String(), 'ends_at' => $booking->ends_at->toIso8601String(),
            'expires_at' => $booking->hold_expires_at?->toIso8601String(), 'cart_token' => $token,
        ]], 201);

        return $token === null ? $response : $response->header('X-Cart-Token', $token);
    }

    /**
     * My bookings
     *
     * What is coming first (soonest first), then the past (newest first). Each says what I can do (`can_cancel`, `can_reschedule`, `can_complete`) and what cancelling would cost (`if_cancelled`).
     */
    #[ResponseDoc(status: 200, type: 'array{data: list<'.self::BOOKING.'>}')]
    public function index(Request $request, MyBookings $bookings, CustomerOrders $orders): JsonResponse
    {
        return response()->json(['data' => array_map(fn (array $r): array => $this->resource($r), $bookings->forOrders($orders->orderIdsOwnedBy($this->userId($request)), app()->getLocale()))]);
    }

    /**
     * One booking
     */
    #[ResponseDoc(status: 200, type: 'array{data: '.self::BOOKING.'}')]
    public function show(Request $request, string $id, MyBookings $bookings, CustomerOrders $orders): JsonResponse
    {
        $row = $bookings->find($orders->orderIdsOwnedBy($this->userId($request)), $id, app()->getLocale()) ?? throw new ApiProblem(ErrorCode::NotFound);

        return response()->json(['data' => $this->resource($row)]);
    }

    /**
     * Cancel a booking
     *
     * Under the service's cancellation terms: `if_cancelled` on the booking says what it would cost and what is refunded.
     *
     * @status 204
     */
    public function cancel(Request $request, string $id, CancelBooking $cancel): Response
    {
        $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return $this->attempt(fn () => $cancel->handle($this->actor($request), $id, $request->input('reason') === null ? null : (string) $request->input('reason')));
    }

    /**
     * Move a booking to another time
     *
     * `start` is the `starts_at` of a free time for the same service and person (`GET /services/{sku}/slots?resource=`). Allowed only with enough notice and while moves are left.
     *
     * @status 204
     */
    public function reschedule(Request $request, string $id, RescheduleBooking $reschedule): Response
    {
        $data = $request->validate(['start' => ['required', 'string', 'max:40']]);

        return $this->attempt(fn () => $reschedule->handle($this->actor($request), $id, $data['start']));
    }

    /**
     * Mark a booking done
     *
     * After the time has come.
     *
     * @status 204
     */
    public function complete(Request $request, string $id, CompleteBooking $complete): Response
    {
        return $this->attempt(fn () => $complete->handle($this->actor($request), $id));
    }

    private function attempt(callable $do): Response
    {
        try {
            $do();
        } catch (BookingException $e) {
            throw $this->problem($e);
        }

        return response()->noContent();
    }

    private function problem(BookingException $e): ApiProblem
    {
        if (in_array($e->reason, ['not_found', 'booking_not_found'], true)) {
            return new ApiProblem(ErrorCode::NotFound);
        }

        $code = in_array($e->reason, ['slot_taken', 'slot_unavailable'], true) ? ErrorCode::BookingSlotTaken : ErrorCode::RequestRefused;

        return new ApiProblem($code, ['params' => ['reason' => $e->reason] + $e->params], detail: (string) __('bookings::errors.'.$e->reason, $e->params));
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function resource(array $r): array
    {
        $outcome = fn (?array $o): ?array => $o === null ? null : array_map(fn ($v) => $v instanceof Money ? MoneyData::from($v) : $v, $o);

        return [
            'id' => $r['public_id'], 'status' => $r['status'], 'service' => $r['service'], 'resource' => $r['resource'], 'starts_at' => $r['starts_at']->toIso8601String(), 'ends_at' => $r['ends_at']->toIso8601String(),
            'paid' => MoneyData::from($r['paid']), 'order' => ['id' => $r['order_public_id'], 'number' => $r['order_number']], 'upcoming' => $r['upcoming'],
            'can_cancel' => $r['can_cancel'], 'can_reschedule' => $r['can_move'], 'can_complete' => $r['can_complete'], 'reschedules_left' => $r['moves_left'], 'reschedule_notice_hours' => $r['move_hours'],
            'if_cancelled' => $outcome($r['if_cancelled']), 'ended' => $outcome($r['ended']), 'meeting_url' => $r['meeting_url'],
        ];
    }

    private function actor(Request $request): Actor
    {
        return new Actor(ActorType::Customer, $this->userId($request));
    }

    private function userId(Request $request): int
    {
        return (int) $request->user()?->getAuthIdentifier();
    }
}
