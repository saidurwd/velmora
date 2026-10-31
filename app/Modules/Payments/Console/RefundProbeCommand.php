<?php

declare(strict_types=1);

namespace App\Modules\Payments\Console;

use App\Modules\Payments\Actions\ApproveRefund;
use App\Modules\Payments\Actions\ExecuteRefund;
use App\Modules\Payments\Actions\RejectRefund;
use App\Modules\Payments\Actions\RequestRefund;
use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Gateways\FakeGateway;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use Illuminate\Console\Command;
use Throwable;

/**
 * Concurrency-test entry point: each OS process (its own database connection) does ONE refund step and prints a JSON line.
 * `gateway_calls` is how many times THIS process called the fake gateway's refund. Never available in production.
 */
class RefundProbeCommand extends Command
{
    protected $signature = 'payments:refund-probe {action : request|approve|reject|execute} {--payment=} {--refund=} {--amount=0} {--actor-id=} {--start-at=}';

    protected $description = 'Request, approve, reject or send one refund and print the outcome as JSON (concurrency testing only)';

    protected $hidden = true;

    public function handle(FakeGateway $gateway, RequestRefund $request, ApproveRefund $approve, RejectRefund $reject, ExecuteRefund $execute): int
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
            $refund = match ((string) $this->argument('action')) {
                'request' => $request->handle($actor, (string) $this->option('payment'), (int) $this->option('amount'), 'concurrency probe'),
                'approve' => $approve->handle($actor, (string) $this->option('refund')),
                'reject' => $reject->handle($actor, (string) $this->option('refund'), 'concurrency probe'),
                default => $execute->handle($actor, (string) $this->option('refund')),
            };
            $this->line(json_encode(['ok' => true, 'refund' => $refund->public_id, 'status' => $refund->status->value, 'gateway_calls' => count($gateway->refunds)], JSON_THROW_ON_ERROR));
        } catch (PaymentException $e) {
            $this->line(json_encode(['ok' => false, 'reason' => $e->reason, 'gateway_calls' => count($gateway->refunds)], JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            $this->line(json_encode(['ok' => false, 'reason' => 'error', 'class' => $e::class, 'message' => $e->getMessage()], JSON_THROW_ON_ERROR));
        }

        return self::SUCCESS;
    }
}
