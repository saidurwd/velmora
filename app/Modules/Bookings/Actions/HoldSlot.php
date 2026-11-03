<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Actions;

use App\Modules\Bookings\Exceptions\BookingException;
use App\Modules\Bookings\Models\ServiceBooking;
use App\Modules\Bookings\Models\ServiceDefinition;
use App\Modules\Bookings\Models\ServicePackage;
use App\Modules\Bookings\Services\BookingEvents;
use App\Modules\Bookings\Services\SlotSearch;
use App\Modules\Cart\Contracts\HeldCartLines;
use App\Modules\Cart\DTOs\CartOwner;
use App\Modules\Catalog\Contracts\VariantDirectory;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Support\Transactions;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Keeps a slot for a visitor while they pay. The time must be one the slot search would offer right now on that resource (so a made-up time, or one inside
 * another booking's buffer, is refused before anything is written). Lapsed holds on that resource are cleared first. Then the row is written: the database's
 * own guard decides a race, so of any number of visitors pressing the same slot at once exactly one holds it and the rest are told it is taken. A deadlock
 * is retried a few times. The booking is put in the visitor's cart in the same transaction. A visitor has at most three live holds, and choosing another time
 * for the same service replaces their earlier hold.
 */
final class HoldSlot
{
    public const MAX_LIVE_HOLDS = 3;

    public function __construct(private readonly VariantDirectory $variants, private readonly SlotSearch $search, private readonly HeldCartLines $cart, private readonly BookingEvents $events, private readonly Clock $clock) {}

    /** @throws BookingException variant_unknown | package_unavailable | slot_unavailable | slot_taken | too_many_holds | time_invalid */
    public function handle(CartOwner $owner, string $variantSku, string $resourcePublicId, string $startUtc): ServiceBooking
    {
        $variant = $this->variants->findBySku($variantSku) ?? throw new BookingException('variant_unknown');
        $package = ServicePackage::query()->where('variant_id', $variant->id)->first() ?? throw new BookingException('package_unavailable');
        $service = ServiceDefinition::query()->find($package->service_id);
        if ($service === null || ! $service->is_active || ! $package->is_active) {
            throw new BookingException('package_unavailable');
        }
        $start = $this->parse($startUtc);
        if (! $this->search->offers($package, $resourcePublicId, $start)) {
            throw new BookingException('slot_unavailable');
        }
        $resourceId = (int) DB::table('service_resources')->where('public_id', $resourcePublicId)->value('id');
        $key = $owner->key();

        return Transactions::run(function () use ($owner, $variant, $package, $service, $start, $resourceId, $key): ServiceBooking {
            $now = $this->clock->now();
            $end = $start->addMinutes($package->duration_min);
            $from = $start->subMinutes($package->buffer_before_min);
            $until = $end->addMinutes($package->buffer_after_min);

            // the visitor's own earlier hold for this service gives way to the new choice
            foreach (ServiceBooking::query()->where('holder_key', $key)->where('package_id', $package->id)->where('status', 'held')->whereNull('order_item_id')->lockForUpdate()->get() as $old) {
                $this->cancel($old->id, 'hold_replaced');
            }
            $live = ServiceBooking::query()->where('holder_key', $key)->where('status', 'held')->where('hold_expires_at', '>', $now)->count();   // index (holder_key, status)
            if ($live >= self::MAX_LIVE_HOLDS) {
                throw new BookingException('too_many_holds', ['max' => self::MAX_LIVE_HOLDS]);
            }

            // holds that ran out on this resource around this time give the time back before we try (the guard would still count them)
            foreach (DB::table('service_bookings')->where('resource_id', $resourceId)->where('status', 'held')->where('hold_expires_at', '<=', $now)->where('blocks_from', '<', $until)->where('blocks_until', '>', $from)->lockForUpdate()->pluck('id') as $stale) {
                $this->cancel((int) $stale, 'hold_expired');
            }

            try {
                $booking = ServiceBooking::query()->create([
                    'public_id' => strtolower((string) Str::ulid()), 'package_id' => $package->id, 'resource_id' => $resourceId, 'user_id' => $owner->userId, 'holder_key' => $key, 'status' => 'held',
                    'starts_at' => $start, 'ends_at' => $end, 'blocks_from' => $from, 'blocks_until' => $until, 'hold_expires_at' => $now->addMinutes($service->hold_minutes),
                ]);
            } catch (QueryException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                    throw new BookingException('slot_taken'); // somebody holds or has booked it: the guard said so
                }

                throw $e;
            }
            $this->events->add($booking->id, 'held', $owner->userId === null ? 'guest' : 'customer', $owner->userId);
            try {
                $this->cart->put($owner, $variant->id, $booking->public_id);
            } catch (DomainException) {
                throw new BookingException('not_for_sale'); // no price, or the cart is full: nothing stays held
            }

            return $booking;
        });
    }

    private function cancel(int $bookingId, string $event): void
    {
        $changed = DB::table('service_bookings')->where('id', $bookingId)->where('status', 'held')->update(['status' => 'cancelled', 'updated_at' => $this->clock->now()]);
        if ($changed === 1) {
            $this->events->add($bookingId, $event);
        }
    }

    /** @throws BookingException time_invalid */
    private function parse(string $value): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value, 'UTC')->utc();
        } catch (Throwable) {
            throw new BookingException('time_invalid');
        }
    }
}
