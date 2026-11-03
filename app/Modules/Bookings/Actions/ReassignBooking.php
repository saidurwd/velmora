<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Actions;

use App\Modules\Bookings\Events\BookingRescheduled;
use App\Modules\Bookings\Exceptions\BookingException;
use App\Modules\Bookings\Models\ServicePackage;
use App\Modules\Bookings\Services\BookingAccess;
use App\Modules\Bookings\Services\BookingEvents;
use App\Modules\Bookings\Services\SlotSearch;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Outbox\Outbox;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Transactions;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Staff give a confirmed booking to another person or room that does the same service, at the same time. The slot search must offer that time on the new resource; the guard decides a race. */
final class ReassignBooking
{
    public function __construct(private readonly BookingAccess $access, private readonly SlotSearch $search, private readonly BookingEvents $events, private readonly Clock $clock, private readonly Outbox $outbox, private readonly AuditLogger $audit) {}

    /** @throws BookingException not_found | not_reschedulable | resource_invalid | same_time | slot_unavailable | slot_taken */
    public function handle(Actor $actor, string $bookingPublicId, string $newResourcePublicId): void
    {
        if (! $actor->isStaff()) {
            throw new BookingException('not_found');
        }

        Transactions::run(function () use ($actor, $bookingPublicId, $newResourcePublicId): void {
            $b = $this->access->lock($actor, $bookingPublicId);
            if ($b->status !== 'confirmed') {
                throw new BookingException('not_reschedulable');
            }
            $package = ServicePackage::query()->findOrFail((int) $b->package_id);
            $new = DB::table('service_resources as r')->join('service_resource_links as l', 'l.resource_id', '=', 'r.id')
                ->where('r.public_id', $newResourcePublicId)->where('r.is_active', true)->where('l.service_id', $package->service_id)->first(['r.id']);   // unique public_id, then (service_id, resource_id)
            if ($new === null) {
                throw new BookingException('resource_invalid');
            }
            if ((int) $new->id === (int) $b->resource_id) {
                throw new BookingException('same_time');
            }
            if (! $this->search->offers($package, $newResourcePublicId, CarbonImmutable::parse((string) $b->starts_at, 'UTC'), (int) $b->id)) {
                throw new BookingException('slot_unavailable');
            }

            try {
                $changed = DB::table('service_bookings')->where('id', $b->id)->where('status', 'confirmed')->where('resource_id', $b->resource_id)->update(['resource_id' => $new->id, 'updated_at' => $this->clock->now()]);
            } catch (QueryException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                    throw new BookingException('slot_taken');
                }

                throw $e;
            }
            if ($changed !== 1) {
                throw new BookingException('not_reschedulable');
            }

            $this->events->add((int) $b->id, 'reassigned', $actor->type->value, $actor->id);
            $this->outbox->record(new BookingRescheduled((int) $b->id, (string) $b->public_id));
            $this->audit->record('bookings.reassigned', 'booking', ['resource_id' => (int) $b->resource_id], ['resource_id' => (int) $new->id], $actor, (int) $b->id);
        });
    }
}
