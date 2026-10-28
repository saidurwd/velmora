<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Console;

use App\Modules\Inventory\Actions\CommitReservation;
use App\Modules\Inventory\Actions\ReleaseReservation;
use App\Modules\Inventory\Actions\ReserveStock;
use App\Modules\Inventory\Exceptions\InventoryException;
use Illuminate\Console\Command;
use Throwable;

/**
 * Diagnostic entry point used by the concurrency tests: each OS process runs ONE stock operation and prints a JSON line.
 * --start-at makes many processes fire at the same instant. Refuses to run in production.
 */
class StockProbeCommand extends Command
{
    protected $signature = 'inventory:probe {op : reserve|lines|commit|release} {--variant=} {--qty=1} {--lines= : variant:qty,variant:qty} {--owner=probe} {--reservation=} {--start-at= : unix time with decimals}';

    protected $description = 'Run one stock operation and print the outcome as JSON (concurrency testing only)';

    protected $hidden = true;

    public function handle(ReserveStock $reserve, CommitReservation $commit, ReleaseReservation $release): int
    {
        if (app()->environment('production')) {
            $this->error('Not available in production.');

            return self::FAILURE;
        }

        if ($at = (float) $this->option('start-at')) {
            while (microtime(true) < $at) {
                usleep(200); // line every process up on the same instant
            }
        }

        try {
            $result = match ($this->argument('op')) {
                'reserve' => ['reservation' => $reserve->handle((int) $this->option('variant'), (int) $this->option('qty'), 'order', (string) $this->option('owner'))->public_id],
                'lines' => ['reservations' => count($reserve->forLines($this->parseLines((string) $this->option('lines')), 'order', (string) $this->option('owner')))],
                'commit' => ['committed' => $commit->handle((string) $this->option('reservation'))],
                'release' => ['released' => $release->handle((string) $this->option('reservation'))],
                default => throw new \InvalidArgumentException('unknown op'),
            };
            $this->line(json_encode(['ok' => true] + $result, JSON_THROW_ON_ERROR));
        } catch (InventoryException $e) {
            $this->line(json_encode(['ok' => false, 'code' => $e->errorCode], JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            $this->line(json_encode(['ok' => false, 'code' => 'error', 'class' => $e::class, 'message' => $e->getMessage()], JSON_THROW_ON_ERROR));
        }

        return self::SUCCESS;
    }

    /** @return array<int, int> */
    private function parseLines(string $spec): array
    {
        $lines = [];
        foreach (explode(',', $spec) as $pair) {
            [$v, $q] = array_map('intval', explode(':', $pair));
            $lines[$v] = $q;
        }

        return $lines;
    }
}
