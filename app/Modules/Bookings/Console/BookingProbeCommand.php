<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Console;

use App\Modules\Bookings\Actions\CancelBooking;
use App\Modules\Bookings\Actions\ClaimBookingHold;
use App\Modules\Bookings\Actions\HoldSlot;
use App\Modules\Bookings\Actions\RescheduleBooking;
use App\Modules\Bookings\Exceptions\BookingException;
use App\Modules\Cart\DTOs\CartOwner;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Support\Transactions;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Concurrency-test entry point: each OS process (its own database connection) tries to take ONE slot and prints a JSON line. It writes the row exactly as
 * the booking flow will, so what is proved here is the database's own guard. Never available in production.
 */
class BookingProbeCommand extends Command
{
    protected $signature = 'bookings:probe {action : hold|hold-slot|claim|cancel|reschedule} {--booking=} {--hold-ref=} {--variant=} {--order-item=} {--resource=} {--package=} {--start=} {--minutes=60} {--sku=} {--resource-public=} {--token=} {--start-at=}';

    protected $description = 'Try to hold one slot and print the outcome as JSON (concurrency testing only)';

    protected $hidden = true;

    public function handle(HoldSlot $holdSlot, ClaimBookingHold $claim, CancelBooking $cancel, RescheduleBooking $reschedule): int
    {
        if (app()->environment('production')) {
            $this->error('Not available in production.');

            return self::FAILURE;
        }

        if ($at = (float) $this->option('start-at')) {
            while (microtime(true) < $at) {
                usleep(200);
            }
        }

        if (in_array($this->argument('action'), ['cancel', 'reschedule'], true)) {
            // staff cancel or move one booking, exactly as the real actions do
            $staff = new Actor(ActorType::Staff, 1, null, 'probe');
            try {
                if ($this->argument('action') === 'cancel') {
                    $r = $cancel->handle($staff, (string) $this->option('booking'), 'probe');
                    $this->line(json_encode(['ok' => true, 'done' => true, 'fee_minor' => $r->fee_minor], JSON_THROW_ON_ERROR));
                } else {
                    $reschedule->handle($staff, (string) $this->option('booking'), (string) $this->option('start'), true, 'probe');
                    $this->line(json_encode(['ok' => true, 'done' => true], JSON_THROW_ON_ERROR));
                }
            } catch (BookingException $e) {
                $this->line(json_encode(['ok' => true, 'done' => false, 'reason' => $e->reason], JSON_THROW_ON_ERROR));
            } catch (Throwable $e) {
                $this->line(json_encode(['ok' => false, 'class' => $e::class, 'message' => $e->getMessage()], JSON_THROW_ON_ERROR));
            }

            return self::SUCCESS;
        }

        if ($this->argument('action') === 'claim') {
            // the real claim checkout makes, in its own transaction, as one order trying to take a visitor's hold
            try {
                Transactions::run(fn () => $claim->claim((string) $this->option('hold-ref'), (int) $this->option('variant'), (int) $this->option('order-item')));
                $this->line(json_encode(['ok' => true, 'claimed' => true], JSON_THROW_ON_ERROR));
            } catch (DomainException $e) {
                $this->line(json_encode(['ok' => true, 'claimed' => false, 'reason' => $e->getMessage()], JSON_THROW_ON_ERROR));
            } catch (Throwable $e) {
                $this->line(json_encode(['ok' => false, 'class' => $e::class, 'message' => $e->getMessage()], JSON_THROW_ON_ERROR));
            }

            return self::SUCCESS;
        }

        if ($this->argument('action') === 'hold-slot') {
            // the real thing: the same action the "Book" button runs, as one visitor with their own cart
            try {
                $booking = $holdSlot->handle(CartOwner::guest((string) $this->option('token')), (string) $this->option('sku'), (string) $this->option('resource-public'), (string) $this->option('start'));
                $this->line(json_encode(['ok' => true, 'held' => true, 'booking' => $booking->public_id], JSON_THROW_ON_ERROR));
            } catch (BookingException $e) {
                $this->line(json_encode(['ok' => true, 'held' => false, 'reason' => $e->reason], JSON_THROW_ON_ERROR));
            } catch (Throwable $e) {
                $this->line(json_encode(['ok' => false, 'class' => $e::class, 'message' => $e->getMessage()], JSON_THROW_ON_ERROR));
            }

            return self::SUCCESS;
        }

        $package = DB::table('service_packages')->where('id', (int) $this->option('package'))->first();
        $start = CarbonImmutable::parse((string) $this->option('start'), 'UTC');
        $end = $start->addMinutes((int) $this->option('minutes'));

        $row = [
            'public_id' => strtolower((string) Str::ulid()), 'package_id' => (int) $this->option('package'), 'resource_id' => (int) $this->option('resource'), 'status' => 'held', 'starts_at' => $start, 'ends_at' => $end,
            'blocks_from' => $start->subMinutes((int) ($package->buffer_before_min ?? 0)), 'blocks_until' => $end->addMinutes((int) ($package->buffer_after_min ?? 0)), 'hold_expires_at' => now()->addMinutes(15), 'created_at' => now(), 'updated_at' => now(),
        ];

        // Many inserts hitting the guard at once can make the database pick a deadlock victim. That is safe (nothing is double booked) but the booking would be
        // refused for no good reason, so a deadlock is retried a few times, exactly as the real hold will do.
        $deadlocks = 0;
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                DB::table('service_bookings')->insert($row);
                $this->line(json_encode(['ok' => true, 'held' => true, 'deadlocks' => $deadlocks], JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            } catch (QueryException $e) {
                if (in_array((int) ($e->errorInfo[1] ?? 0), [1213, 1205], true)) {
                    $deadlocks++;
                    usleep(random_int(5_000, 40_000));

                    continue;
                }
                $this->line(json_encode(['ok' => true, 'held' => false, 'duplicate' => (int) ($e->errorInfo[1] ?? 0) === 1062, 'deadlocks' => $deadlocks], JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }
        }
        $this->line(json_encode(['ok' => true, 'held' => false, 'duplicate' => false, 'deadlocks' => $deadlocks], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
