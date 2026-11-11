<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Console;

use App\Modules\Inventory\Actions\ApplyStockImport;
use App\Modules\Inventory\Actions\CreateStockTransfer;
use App\Modules\Inventory\Actions\DecideStockCount;
use App\Modules\Inventory\Actions\OpenStockCount;
use App\Modules\Inventory\Actions\ReceiveStockTransfer;
use App\Modules\Inventory\Actions\RecordStockCount;
use App\Modules\Inventory\Actions\RollbackStockImport;
use App\Modules\Inventory\Actions\SubmitStockCount;
use App\Modules\Inventory\Exceptions\StockOpsException;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use Illuminate\Console\Command;
use Throwable;

/** Concurrency-test entry point: each OS process (its own database connection) does ONE stock operation and prints a JSON line. Never available in production. */
class StockOpsProbeCommand extends Command
{
    protected $signature = 'inventory:ops-probe {action : send|receive|open-count|record-count|submit-count|approve-count|reject-count|apply-import|rollback-import} {--from=main} {--to=shop} {--lines=} {--transfer=} {--import=} {--count=} {--location=main} {--variant=} {--qty=0} {--actor-id=} {--start-at=}';

    protected $description = 'Do one transfer or stock-take step and print the outcome as JSON (concurrency testing only)';

    protected $hidden = true;

    public function handle(CreateStockTransfer $send, ReceiveStockTransfer $receive, OpenStockCount $open, RecordStockCount $record, SubmitStockCount $submit, DecideStockCount $decide, ApplyStockImport $apply, RollbackStockImport $rollback): int
    {
        if (app()->environment('production')) {
            $this->error('Not available in production.');

            return self::FAILURE;
        }

        $actor = new Actor(ActorType::Staff, $this->option('actor-id') === null ? null : (int) $this->option('actor-id'), null, 'probe');
        $lines = [];
        foreach (array_filter(explode(',', (string) $this->option('lines'))) as $pair) {
            [$variant, $qty] = explode(':', $pair);
            $lines[(int) $variant] = (int) $qty;
        }

        if ($at = (float) $this->option('start-at')) {
            while (microtime(true) < $at) {
                usleep(200);
            }
        }

        try {
            $ref = match ((string) $this->argument('action')) {
                'send' => $send->handle($actor, (string) $this->option('from'), (string) $this->option('to'), $lines)->public_id,
                'receive' => $receive->handle($actor, (string) $this->option('transfer'))->public_id,
                'open-count' => $open->handle($actor, (string) $this->option('location'))->public_id,
                'record-count' => $this->recorded($record, $actor),
                'apply-import' => $this->applied($apply, $actor),
                'rollback-import' => $this->rolledBack($rollback, $actor),
                'submit-count' => $submit->handle($actor, (string) $this->option('count'))->public_id,
                'approve-count' => $decide->approve($actor, (string) $this->option('count'))->public_id,
                default => $decide->reject($actor, (string) $this->option('count'), 'concurrency probe')->public_id,
            };
            $this->line(json_encode(['ok' => true, 'ref' => $ref], JSON_THROW_ON_ERROR));
        } catch (StockOpsException $e) {
            $this->line(json_encode(['ok' => false, 'reason' => $e->reason], JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            $this->line(json_encode(['ok' => false, 'reason' => 'error', 'class' => $e::class, 'message' => $e->getMessage()], JSON_THROW_ON_ERROR));
        }

        return self::SUCCESS;
    }

    private function applied(ApplyStockImport $apply, Actor $actor): string
    {
        $apply->handle($actor, (string) $this->option('import'));

        return (string) $this->option('import');
    }

    private function rolledBack(RollbackStockImport $rollback, Actor $actor): string
    {
        $rollback->handle($actor, (string) $this->option('import'));

        return (string) $this->option('import');
    }

    private function recorded(RecordStockCount $record, Actor $actor): string
    {
        $record->handle($actor, (string) $this->option('count'), (int) $this->option('variant'), (int) $this->option('qty'));

        return (string) $this->option('count');
    }
}
