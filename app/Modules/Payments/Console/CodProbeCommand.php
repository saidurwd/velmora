<?php

declare(strict_types=1);

namespace App\Modules\Payments\Console;

use App\Modules\Payments\Actions\RecordCodCollection;
use App\Modules\Payments\Actions\RecordCodRemittance;
use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use Illuminate\Console\Command;
use Throwable;

/**
 * Concurrency-test entry point: each OS process (its own database connection) records ONE cash collection or ONE courier statement
 * and prints a JSON line. Never available in production.
 */
class CodProbeCommand extends Command
{
    protected $signature = 'payments:cod-probe {action : collect|remit} {--order=} {--courier=Pathao} {--statement=} {--received=0} {--parcel=*} {--amount=*} {--actor-id=} {--start-at=}';

    protected $description = 'Record one cash collection or one courier statement and print the outcome as JSON (concurrency testing only)';

    protected $hidden = true;

    public function handle(RecordCodCollection $collect, RecordCodRemittance $remit): int
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
            if ($this->argument('action') === 'collect') {
                $result = $collect->handle($actor, (string) $this->option('order'));
                $this->line(json_encode(['ok' => true, 'collection' => $result->public_id], JSON_THROW_ON_ERROR));
            } else {
                $parcels = (array) $this->option('parcel');
                $amounts = (array) $this->option('amount');
                $items = [];
                foreach ($parcels as $i => $parcel) {
                    $items[] = ['collection' => (string) $parcel, 'amount_minor' => (int) $amounts[$i]];
                }
                $result = $remit->handle($actor, (string) $this->option('courier'), (string) $this->option('statement'), (int) $this->option('received'), null, $items);
                $this->line(json_encode(['ok' => true, 'remittance' => $result->public_id, 'status' => $result->status], JSON_THROW_ON_ERROR));
            }
        } catch (PaymentException $e) {
            $this->line(json_encode(['ok' => false, 'reason' => $e->reason], JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            $this->line(json_encode(['ok' => false, 'reason' => 'error', 'class' => $e::class, 'message' => $e->getMessage()], JSON_THROW_ON_ERROR));
        }

        return self::SUCCESS;
    }
}
