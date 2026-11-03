<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Actions;

use App\Modules\Bookings\Events\BookingRescheduled;
use App\Modules\Bookings\Exceptions\BookingException;
use App\Modules\Bookings\Models\ServicePackage;
use App\Modules\Bookings\Services\BookingAccess;
use App\Modules\Bookings\Services\BookingEvents;
use App\Modules\Bookings\Services\CancellationPolicies;
use App\Modules\Bookings\Services\SlotSearch;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Outbox\Outbox;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Transactions;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Moves a confirmed booking to another start on the same resource, keeping its order line and its terms. The new start must be one the slot search would offer
 * (the booking's own time counts as free, so it may move by a little), and the database's guard has the last word if two people want the same time. A customer
 * may move their own booking only before the time set by its terms, and only as often as the terms allow; staff may override both with a reason.
 */
final class RescheduleBooking
{
    public function __construct(
        private readonly BookingAccess $access, private readonly CancellationPolicies $policies, private readonly SlotSearch $search, private readonly BookingEvents $events,
        private readonly Clock $clock, private readonly Outbox $outbox, private readonly AuditLogger $audit,
    ) {}

    /** @throws BookingException not_found | not_reschedulable | already_started | reschedule_too_late | reschedule_limit | same_time | time_invalid | slot_unavailable | slot_taken | reason_required */
    public function handle(Actor $actor, string $bookingPublicId, string $startUtc, bool $override = false, ?string $reason = null): void
    {
        $start = $this->parse($startUtc);
        $override = $override && $actor->type === ActorType::Staff;
        if ($override && trim((string) $reason) === '') {
            throw new BookingException('reason_required');
        }

        Transactions::run(function () use ($actor, $bookingPublicId, $start, $override, $reason): void {
            $b = $this->access->lock($actor, $bookingPublicId);
            if ($b->status !== 'confirmed') {
                throw new BookingException('not_reschedulable');
            }
            $now = $this->clock->now();
            $minutesBefore = (int) floor((strtotime((string) $b->starts_at.' UTC') - $now->getTimestamp()) / 60);
            if (! $override) {
                $terms = $this->policies->termsOf($b);
                if ($minutesBefore <= 0) {
                    throw new BookingException('already_started');
                }
                if ($minutesBefore < $terms['reschedule_hours'] * 60) {
                    throw new BookingException('reschedule_too_late', ['hours' => $terms['reschedule_hours']]);
                }
                if ((int) $b->reschedule_count >= $terms['max_reschedules']) {
                    throw new BookingException('reschedule_limit', ['max' => $terms['max_reschedules']]);
                }
            }
            if (CarbonImmutable::parse((string) $b->starts_at, 'UTC')->equalTo($start)) {
                throw new BookingException('same_time');
            }

            $package = ServicePackage::query()->findOrFail((int) $b->package_id);
            $resource = (string) DB::table('service_resources')->where('id', $b->resource_id)->value('public_id');
            if (! $this->search->offers($package, $resource, $start, (int) $b->id)) {
                throw new BookingException('slot_unavailable');
            }

            $end = $start->addMinutes($package->duration_min);
            try {
                $changed = DB::table('service_bookings')->where('id', $b->id)->where('status', 'confirmed')->update([
                    'starts_at' => $start, 'ends_at' => $end, 'blocks_from' => $start->subMinutes($package->buffer_before_min), 'blocks_until' => $end->addMinutes($package->buffer_after_min),
                    'reschedule_count' => DB::raw('reschedule_count + 1'), 'updated_at' => $now,
                ]);
            } catch (QueryException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                    throw new BookingException('slot_taken');   // the guard: somebody else holds or has booked that time
                }

                throw $e;
            }
            if ($changed !== 1) {
                throw new BookingException('not_reschedulable');
            }

            $this->events->add((int) $b->id, 'rescheduled', $actor->type->value, $actor->id, 'from '.CarbonImmutable::parse((string) $b->starts_at, 'UTC')->format('Y-m-d H:i').' UTC'.($override ? ' (staff override: '.mb_substr(trim((string) $reason), 0, 120).')' : ''));
            $this->outbox->record(new BookingRescheduled((int) $b->id, (string) $b->public_id));
            $this->audit->record('bookings.rescheduled', 'booking', ['starts_at' => (string) $b->starts_at], ['starts_at' => $start->format('Y-m-d H:i:s'), 'override' => $override, 'reason' => $reason], $actor, (int) $b->id);
        });
    }

    private function parse(string $value): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value, 'UTC')->utc();
        } catch (Throwable) {
            throw new BookingException('time_invalid');
        }
    }
}
