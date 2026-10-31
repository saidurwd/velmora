<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Orders\Contracts\CodOrders;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\PaymentStatus;
use App\Modules\Payments\Events\PaymentSucceeded;
use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Models\CodCollection;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Outbox\Outbox;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Platform\Support\Transactions;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records that the cash for a cash-on-delivery order was collected at the door. The amount is always the order's total (nobody types it, so it
 * cannot be mistyped), it can be recorded once per order (a database UNIQUE, so two people recording the same delivery at once cannot both
 * succeed), and it becomes a normal paid payment with one succeeded attempt, so a later refund works exactly like any other. The order follows
 * through the outbox. Who may record (permission `payments.cod`) is decided by the caller.
 */
final class RecordCodCollection
{
    public const PROVIDER = 'cod';

    public function __construct(private readonly CodOrders $orders, private readonly Outbox $outbox, private readonly AuditLogger $audit, private readonly Clock $clock) {}

    /** @throws PaymentException */
    public function handle(Actor $actor, string $orderPublicId, ?CarbonImmutable $collectedAt = null, ?string $note = null, string $source = 'manual'): CodCollection
    {
        $order = $this->orders->find($orderPublicId) ?? throw new PaymentException('cod_order_not_found');
        if (! $order->isCod) {
            throw new PaymentException('cod_not_cod_order');
        }
        if ($order->status === OrderStatus::Cancelled) {
            throw new PaymentException('cod_order_cancelled');
        }
        if ($order->paymentStatus !== PaymentStatus::Unpaid) {
            throw new PaymentException('cod_already_collected');
        }

        $now = $this->clock->now();
        $collectedAt ??= $now;
        if ($collectedAt->greaterThan($now->addMinutes(5)) || $collectedAt->lessThan($order->placedAt)) {
            throw new PaymentException('cod_date_invalid');
        }
        $note = $note === null ? null : (mb_substr(trim(BanglaText::input($note)), 0, 255) ?: null);
        $source = $source === 'courier' ? 'courier' : 'manual';

        try {
            $collection = Transactions::run(function () use ($actor, $order, $collectedAt, $note, $source, $now): CodCollection {
                $paymentId = DB::table('payments')->insertGetId([
                    'public_id' => $this->ulid(), 'order_id' => $order->orderId, 'method_code' => self::PROVIDER, 'provider' => self::PROVIDER,
                    'amount_minor' => $order->amountMinor, 'currency' => $order->currency, 'status' => 'paid', 'captured_minor' => $order->amountMinor,
                    'paid_at' => $collectedAt, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $transactionRef = 'COD-'.$order->orderNumber;
                $collection = CodCollection::query()->create([
                    'public_id' => $this->ulid(), 'order_id' => $order->orderId, 'payment_id' => $paymentId, 'amount_minor' => $order->amountMinor, 'currency' => $order->currency,
                    'source' => $source, 'collected_at' => $collectedAt, 'recorded_by' => $actor->id, 'note' => $note, 'created_at' => $now,
                ]);
                // the collection, the payment and its one attempt exist together or not at all; the attempt's bank id is the collection's id
                DB::table('payment_transactions')->insert([
                    'public_id' => $this->ulid(), 'payment_id' => $paymentId, 'provider' => self::PROVIDER, 'merchant_ref' => $transactionRef, 'attempt' => 1, 'status' => 'succeeded',
                    'provider_txn_id' => $collection->public_id, 'amount_minor' => $order->amountMinor, 'currency' => $order->currency, 'verified_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $paymentPublicId = (string) DB::table('payments')->where('id', $paymentId)->value('public_id');
                $this->outbox->record(new PaymentSucceeded($paymentId, $paymentPublicId, $order->orderId, $order->amountMinor, $order->currency, $transactionRef, 'cod'));
                $this->audit->record('cod.collected', $collection, [], ['order' => $order->orderNumber, 'amount_minor' => $order->amountMinor, 'source' => $source], $actor);

                return $collection;
            });
        } catch (UniqueConstraintViolationException) {
            throw new PaymentException('cod_already_collected'); // someone recorded this delivery a moment earlier
        }

        return $collection;
    }

    private function ulid(): string
    {
        return strtolower((string) Str::ulid());
    }
}
