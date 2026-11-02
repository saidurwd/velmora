<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Console;

use App\Modules\DigitalProducts\Actions\GrantEntitlements;
use Illuminate\Console\Command;
use Throwable;

/**
 * Concurrency-test entry point: each OS process (its own database connection) does ONE thing to ONE order and prints a JSON line.
 * Never available in production.
 */
class DigitalProbeCommand extends Command
{
    protected $signature = 'digital:probe {action : grant} {--order=} {--start-at=}';

    protected $description = 'Grant the rights to download for one paid order and print the outcome as JSON (concurrency testing only)';

    protected $hidden = true;

    public function handle(GrantEntitlements $grant): int
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

        try {
            $this->line(json_encode(['ok' => true, 'made' => $grant->handle((int) $this->option('order'))], JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            $this->line(json_encode(['ok' => false, 'reason' => 'error', 'class' => $e::class, 'message' => $e->getMessage()], JSON_THROW_ON_ERROR));
        }

        return self::SUCCESS;
    }
}
