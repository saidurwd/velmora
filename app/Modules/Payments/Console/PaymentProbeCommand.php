<?php

declare(strict_types=1);

namespace App\Modules\Payments\Console;

use App\Modules\Payments\Actions\ExpireUnpaidPayments;
use App\Modules\Payments\Actions\ProcessPaymentEventAction;
use App\Modules\Payments\Actions\ReceivePaymentNotification;
use App\Modules\Payments\Actions\VerifyPayment;
use App\Modules\Payments\DTOs\VerifiedPayment;
use App\Modules\Payments\Enums\VerifiedStatus;
use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Gateways\FakeGateway;
use App\Modules\Platform\DTOs\Money;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Concurrency-test entry point: each OS process (its own database connection) does ONE thing about a payment and prints a JSON line.
 * The "gateway" is the in-memory FakeGateway, told by the options what it will answer. Never available in production.
 */
class PaymentProbeCommand extends Command
{
    protected $signature = 'payments:probe {mode : verify|ipn|expire} {--ref=} {--answer=valid : valid|failed|pending|none} {--amount-minor=150000} {--currency=BDT} {--bank-id=BANK-1} {--validation-id=VAL-1} {--event-id=} {--claim=VALID} {--start-at=}';

    protected $description = 'Verify, receive a notification for, or expire a payment once and print the outcome as JSON (concurrency testing only)';

    protected $hidden = true;

    public function handle(FakeGateway $gateway, VerifyPayment $verify, ReceivePaymentNotification $receive, ProcessPaymentEventAction $process, ExpireUnpaidPayments $expire): int
    {
        if (app()->environment('production')) {
            $this->error('Not available in production.');

            return self::FAILURE;
        }

        $ref = (string) $this->option('ref');
        $gateway->answer($ref, new VerifiedPayment(
            match ((string) $this->option('answer')) {
                'valid' => VerifiedStatus::Valid, 'failed' => VerifiedStatus::Failed, 'pending' => VerifiedStatus::Pending, default => VerifiedStatus::Unknown,
            },
            $ref, in_array($this->option('answer'), ['valid'], true) ? Money::of((int) $this->option('amount-minor'), (string) $this->option('currency')) : null,
            (string) $this->option('bank-id'), (string) $this->option('validation-id'),
        ));

        if ($at = (float) $this->option('start-at')) {
            while (microtime(true) < $at) {
                usleep(200);
            }
        }

        try {
            $result = match ((string) $this->argument('mode')) {
                'verify' => ['outcome' => $verify->handle($ref, (string) $this->option('validation-id'))->value],
                'expire' => ['expired' => $expire->handle()],
                default => $this->notification($receive, $process, $ref),
            };
            $this->line(json_encode(['ok' => true] + $result, JSON_THROW_ON_ERROR));
        } catch (PaymentException $e) {
            $this->line(json_encode(['ok' => false, 'reason' => $e->reason], JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            $this->line(json_encode(['ok' => false, 'reason' => 'error', 'class' => $e::class, 'message' => $e->getMessage()], JSON_THROW_ON_ERROR));
        }

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function notification(ReceivePaymentNotification $receive, ProcessPaymentEventAction $process, string $ref): array
    {
        $request = Request::create('/ipn', 'POST', [
            'tran_id' => $ref, 'status' => (string) $this->option('claim'), 'val_id' => (string) $this->option('validation-id'), 'amount' => '1500.00',
            'event_id' => (string) $this->option('event-id') ?: null,
        ]);

        $stored = $receive->handle('fake', $request);
        if ($stored) {
            // the queue is switched off in these processes, so the stored notification is worked through here
            $id = (int) DB::table('payment_webhook_events')->where('provider', 'fake')->where('provider_event_id', (string) $this->option('event-id'))->value('id');
            if ($id > 0) {
                $process->handle($id);
            }
        }

        return ['stored' => $stored];
    }
}
