<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Console;

use App\Modules\Notifications\Actions\DeliverNotification;
use Illuminate\Console\Command;
use Throwable;

/** Concurrency-test entry point: each OS process (its own database connection) tries to deliver ONE recorded message and prints a JSON line. Never available in production. */
class NotificationProbeCommand extends Command
{
    protected $signature = 'notifications:probe {id : the delivery record to deliver} {--start-at=}';

    protected $description = 'Try to deliver one recorded message and print the outcome as JSON (concurrency testing only)';

    protected $hidden = true;

    public function handle(DeliverNotification $deliver): int
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
            $this->line(json_encode(['ok' => true, 'result' => $deliver->handle((int) $this->argument('id'))], JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            $this->line(json_encode(['ok' => false, 'class' => $e::class, 'message' => $e->getMessage()], JSON_THROW_ON_ERROR));
        }

        return self::SUCCESS;
    }
}
