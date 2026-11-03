<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Console;

use App\Modules\Bookings\Actions\HoldSlot;
use App\Modules\Bookings\Exceptions\BookingException;
use App\Modules\Cart\DTOs\CartOwner;
use Carbon\CarbonImmutable;
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
    protected $signature = 'bookings:probe {action : hold|hold-slot} {--resource=} {--package=} {--start=} {--minutes=60} {--sku=} {--resource-public=} {--token=} {--start-at=}';

    protected $description = 'Try to hold one slot and print the outcome as JSON (concurrency testing only)';

    protected $hidden = true;

    public function handle(HoldSlot $holdSlot): int
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
