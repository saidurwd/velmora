<?php

declare(strict_types=1);

namespace App\Modules\Payments\Console;

use App\Modules\Payments\Actions\ImportSettlement;
use App\Modules\Payments\Actions\ResolveReconciliationException;
use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Concurrency-test entry point: each OS process (its own database connection) imports ONE settlement file or closes ONE exception and prints a
 * JSON line. Never available in production.
 */
class ReconcileProbeCommand extends Command
{
    protected $signature = 'payments:reconcile-probe {action : import|resolve} {--file=} {--exception=} {--outcome=resolved} {--actor-id=} {--start-at=}';

    protected $description = 'Import one settlement file or close one exception and print the outcome as JSON (concurrency testing only)';

    protected $hidden = true;

    public function handle(ImportSettlement $import, ResolveReconciliationException $resolve): int
    {
        if (app()->environment('production')) {
            $this->error('Not available in production.');

            return self::FAILURE;
        }

        $actor = new Actor(ActorType::Staff, $this->option('actor-id') === null ? null : (int) $this->option('actor-id'), null, 'probe');

        if ($at = (float) $this->option('start-at')) {
            while (microtime(true) < $at) {
                usleep(200);
            }
        }

        try {
            if ($this->argument('action') === 'import') {
                $result = $import->handle($actor, 'fake', (string) file_get_contents((string) $this->option('file')), CarbonImmutable::now('UTC')->subDay(), CarbonImmutable::now('UTC'));
                $this->line(json_encode(['ok' => true, 'import' => $result->public_id, 'problems' => $result->problem_count], JSON_THROW_ON_ERROR));
            } else {
                $result = $resolve->handle($actor, (string) $this->option('exception'), (string) $this->option('outcome'), 'concurrency probe');
                $this->line(json_encode(['ok' => true, 'status' => $result->status], JSON_THROW_ON_ERROR));
            }
        } catch (PaymentException $e) {
            $this->line(json_encode(['ok' => false, 'reason' => $e->reason], JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            $this->line(json_encode(['ok' => false, 'reason' => 'error', 'class' => $e::class, 'message' => $e->getMessage()], JSON_THROW_ON_ERROR));
        }

        return self::SUCCESS;
    }
}
