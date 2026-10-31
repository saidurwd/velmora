<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Models\CodCollection;
use App\Modules\Payments\Models\CodRemittance;
use App\Modules\Payments\Services\PaymentExceptions;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Platform\Support\Transactions;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records the money a courier paid over, against a statement listing the parcels it covers. What we expected is what OUR collections add up to,
 * not what the statement claims. A statement is entered once (UNIQUE courier + statement) and a collection can sit on one statement only
 * (UNIQUE), so neither can be counted twice, even by two people at the same instant. A short or surplus payment, or a parcel whose amount differs
 * from what we collected, becomes a reconciliation exception for finance. Who may record (permission `payments.cod`) is decided by the caller.
 */
final class RecordCodRemittance
{
    public function __construct(private readonly PaymentExceptions $exceptions, private readonly AuditLogger $audit, private readonly Clock $clock) {}

    /**
     * @param  list<array{collection: string, amount_minor: int}>  $items  the parcels the statement lists, with what the courier says it paid for each
     *
     * @throws PaymentException
     */
    public function handle(Actor $actor, string $courier, string $statementRef, int $receivedMinor, ?CarbonImmutable $receivedAt, array $items): CodRemittance
    {
        $courier = mb_substr(trim(BanglaText::input($courier)), 0, 40);
        $statementRef = mb_substr(trim(BanglaText::input($statementRef)), 0, 80);
        if ($courier === '' || $statementRef === '') {
            throw new PaymentException('cod_statement_invalid');
        }
        if ($receivedMinor < 0) {
            throw new PaymentException('cod_amount_invalid');
        }
        if ($items === []) {
            throw new PaymentException('cod_items_required');
        }
        $reported = [];
        foreach ($items as $item) {
            if ($item['amount_minor'] < 0) {
                throw new PaymentException('cod_amount_invalid');
            }
            if (isset($reported[$item['collection']])) {
                throw new PaymentException('cod_items_duplicated');
            }
            $reported[$item['collection']] = $item['amount_minor'];
        }

        $now = $this->clock->now();
        $receivedAt ??= $now;
        if ($receivedAt->greaterThan($now->addMinutes(5))) {
            throw new PaymentException('cod_date_invalid');
        }

        try {
            return Transactions::run(function () use ($actor, $courier, $statementRef, $receivedMinor, $receivedAt, $reported): CodRemittance {
                $collections = CodCollection::query()->whereIn('public_id', array_keys($reported))->get();
                /** @var array<string, CodCollection> $byId */
                $byId = $collections->keyBy('public_id')->all();
                if (count($byId) !== count($reported)) {
                    throw new PaymentException('cod_collection_not_found');
                }
                if ($collections->pluck('currency')->unique()->count() !== 1) {
                    throw new PaymentException('cod_currency_mixed');
                }

                $expected = (int) $collections->sum('amount_minor');
                $differing = [];
                foreach ($reported as $publicId => $amount) {
                    $ours = (int) $byId[$publicId]->amount_minor;
                    if ($ours !== $amount) {
                        $differing[$publicId] = ['collected' => $ours, 'courier_says' => $amount];
                    }
                }
                $status = match (true) {
                    $receivedMinor < $expected => 'short',
                    $receivedMinor > $expected => 'over',
                    $differing !== [] => 'pending', // the total agrees but a parcel does not: a person must look
                    default => 'reconciled',
                };

                $remittance = CodRemittance::query()->create([
                    'public_id' => strtolower((string) Str::ulid()), 'courier' => $courier, 'statement_ref' => $statementRef, 'amount_minor' => $receivedMinor, 'expected_minor' => $expected,
                    'currency' => (string) $collections->first()?->currency, 'status' => $status, 'received_at' => $receivedAt, 'recorded_by' => $actor->id,
                ]);
                DB::table('cod_remittance_items')->insert(array_map(
                    fn (string $publicId, int $amount): array => ['remittance_id' => $remittance->id, 'cod_collection_id' => $byId[$publicId]->id, 'amount_minor' => $amount],
                    array_keys($reported), array_values($reported),
                ));

                $reference = 'cod-remittance:'.$remittance->public_id;
                $details = ['courier' => $courier, 'statement' => $statementRef, 'expected_minor' => $expected, 'received_minor' => $receivedMinor];
                if ($status === 'short' || $status === 'over') {
                    $this->exceptions->raise($status === 'short' ? 'cod_short' : 'cod_over', null, null, $reference, $details + ['difference_minor' => abs($expected - $receivedMinor)]);
                }
                if ($differing !== []) {
                    $this->exceptions->raise('amount_mismatch', null, null, $reference, $details + ['parcels' => $differing]);
                }
                $this->audit->record('cod.remitted', $remittance, [], ['status' => $status, 'expected_minor' => $expected, 'received_minor' => $receivedMinor], $actor);

                return $remittance;
            });
        } catch (UniqueConstraintViolationException $e) {
            throw new PaymentException(str_contains($e->getMessage(), 'cod_remittance_items') ? 'cod_already_remitted' : 'cod_statement_duplicate');
        }
    }
}
