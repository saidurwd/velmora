<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Actions;

use App\Modules\Payments\Contracts\CodCashLedger;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Transactions;
use App\Modules\Shipping\Exceptions\CourierException;
use App\Modules\Shipping\Exceptions\ShipmentException;
use App\Modules\Shipping\Models\CourierStatement;
use App\Modules\Shipping\Models\CourierStatusMapping;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Shipping\Services\CourierRegistry;
use App\Modules\Shipping\Services\ShipmentIssues;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Checks a courier's statement against our own records and records the cash it paid over. The file is a plain CSV with the header
 * `consignment_id,status,cod_amount,courier_fee,delivered_at` (amounts like 1500.00); it is NOT a courier-specific format: no saved courier document describes one.
 *
 *  - The whole file is refused if any line is malformed, and the same file (or statement number) is checked once.
 *  - A parcel we do not know, a status that disagrees with ours, a different cash amount, a delivered cash parcel of this courier in the period that the statement
 *    leaves out, and money paid for nothing each become an exception for a person. A statement never changes a parcel: where the courier can be asked, it is.
 *  - The cash of the delivered parcels is recorded as the courier's payment: what arrived plus what the courier kept must equal what we collected.
 * Who may import (`shipping.manage`) is decided by the caller.
 */
final class ImportCourierStatement
{
    public const MAX_BYTES = 2_000_000;

    public const MAX_ROWS = 20_000;

    public const MAX_PERIOD_DAYS = 31;

    private const HEADER = ['consignment_id', 'status', 'cod_amount', 'courier_fee', 'delivered_at'];

    public function __construct(
        private readonly CourierRegistry $couriers,
        private readonly RecordCourierEvent $record,
        private readonly CodCashLedger $cash,
        private readonly ShipmentIssues $issues,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {}

    /** @throws ShipmentException */
    public function handle(Actor $actor, string $courier, string $statementRef, string $csv, CarbonImmutable $from, CarbonImmutable $to, int $receivedMinor, ?CarbonImmutable $receivedAt = null): CourierStatement
    {
        $statementRef = mb_substr(trim($statementRef), 0, 80);
        if (! $this->couriers->has($courier)) {
            throw new ShipmentException('unknown_courier');
        }
        if ($statementRef === '' || $receivedMinor < 0) {
            throw new ShipmentException('statement_invalid', ['errors' => 'the statement number and the amount received are needed']);
        }
        $now = $this->clock->now();
        if ($to->lessThan($from) || $from->diffInDays($to) > self::MAX_PERIOD_DAYS || $to->greaterThan($now->addMinutes(5))) {
            throw new ShipmentException('statement_window_invalid', ['days' => self::MAX_PERIOD_DAYS]);
        }
        if (strlen($csv) > self::MAX_BYTES) {
            throw new ShipmentException('statement_too_large');
        }

        $normalised = trim(str_replace(["\r\n", "\r"], "\n", preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv));
        $hash = hash('sha256', $normalised);
        if (CourierStatement::query()->where('courier', $courier)->where(fn ($q) => $q->where('file_hash', $hash)->orWhere('statement_ref', $statementRef))->exists()) {
            throw new ShipmentException('statement_already_imported');
        }

        $rows = $this->parse($normalised);
        $shipments = Shipment::query()->where('courier', $courier)->whereIn('courier_consignment_id', array_keys($rows))->get()->keyBy('courier_consignment_id');
        $this->askTheCourier($courier, $rows, $shipments);
        $shipments = Shipment::query()->where('courier', $courier)->whereIn('courier_consignment_id', array_keys($rows))->get()->keyBy('courier_consignment_id');

        $deliveredIds = array_values(array_map('intval', $shipments->filter(fn (Shipment $s): bool => $s->status->value === 'delivered')->pluck('id')->all()));
        $cash = $this->cash->forShipments($deliveredIds);

        $lines = [];
        $problems = [];   // [type, shipmentId|null, reference, details]
        $remit = [];      // collection public id => what the courier says for it
        $fees = 0;
        $seen = [];
        foreach ($rows as $consignment => $row) {
            $shipment = $shipments->get($consignment);
            $fees += $row['fee'];
            $mapped = CourierStatusMapping::query()->where('courier', $courier)->where('provider_status', $row['status'])->value('internal_status');
            $ref = $courier.':'.$statementRef.':'.$consignment;
            $result = 'matched';

            if ($shipment === null) {
                $result = 'unknown_parcel';
                $problems[] = ['statement_unknown_parcel', null, $ref, ['statement' => $statementRef, 'consignment' => $consignment, 'status' => $row['status']]];
            } else {
                $seen[$shipment->id] = true;
                $theirsDelivered = $mapped === 'delivered';
                $oursDelivered = $shipment->status->value === 'delivered';
                if ($theirsDelivered !== $oursDelivered) {
                    $result = 'status_mismatch';
                    $problems[] = ['statement_status_mismatch', $shipment->id, $ref, ['statement' => $statementRef, 'courier_says' => $row['status'], 'we_show' => $shipment->status->value, 'mapped' => $mapped]];
                } elseif ($oursDelivered) {
                    if ($row['cod'] !== $shipment->cod_amount_minor) {
                        $result = 'cod_mismatch';
                        $problems[] = ['statement_cod_mismatch', $shipment->id, $ref, ['statement' => $statementRef, 'courier_cod_minor' => $row['cod'], 'our_cod_minor' => $shipment->cod_amount_minor]];
                    }
                    $line = $cash[$shipment->id] ?? null;
                    if ($line !== null) {
                        if ($line->remitted) {
                            $result = 'already_remitted';
                            $problems[] = ['statement_already_remitted', $shipment->id, $ref, ['statement' => $statementRef, 'consignment' => $consignment]];
                        } else {
                            $remit[$line->collectionPublicId] = $row['cod'];
                        }
                    }
                }
            }
            $lines[] = ['consignment' => $consignment, 'shipment_id' => $shipment?->id, 'status' => $row['status'], 'cod' => $row['cod'], 'fee' => $row['fee'], 'delivered_at' => $row['delivered_at'], 'result' => $result];
        }

        // parcels this courier delivered for cash in the period that the statement does not mention: the courier owes us for them
        foreach (Shipment::query()->where('courier', $courier)->where('status', 'delivered')->where('cod_amount_minor', '>', 0)->whereBetween('delivered_at', [$from->format('Y-m-d H:i:s.u'), $to->format('Y-m-d H:i:s.u')])->orderBy('id')->get() as $missing) {
            if (! isset($seen[$missing->id])) {
                $problems[] = ['statement_missing_parcel', $missing->id, $courier.':'.$statementRef.':'.$missing->public_id, ['statement' => $statementRef, 'shipment' => $missing->public_id, 'our_cod_minor' => $missing->cod_amount_minor]];
            }
        }
        if ($remit === [] && $receivedMinor > 0) {
            $problems[] = ['statement_money_mismatch', null, $courier.':'.$statementRef, ['statement' => $statementRef, 'received_minor' => $receivedMinor, 'reason' => 'no delivered cash parcel on this statement is waiting for its payment']];
        }

        $matched = count(array_filter($lines, fn (array $l): bool => $l['result'] === 'matched'));

        try {
            $statement = Transactions::run(function () use ($actor, $courier, $statementRef, $hash, $from, $to, $receivedMinor, $receivedAt, $fees, $lines, $problems, $remit, $matched, $now): CourierStatement {
                $id = DB::table('courier_statements')->insertGetId([
                    'public_id' => strtolower((string) Str::ulid()), 'courier' => $courier, 'statement_ref' => $statementRef, 'file_hash' => $hash, 'period_from' => $from, 'period_to' => $to,
                    'received_minor' => $receivedMinor, 'fee_minor' => $fees, 'currency' => 'BDT', 'line_count' => count($lines), 'matched_count' => $matched, 'problem_count' => count($problems), 'imported_by' => $actor->id, 'created_at' => $now,
                ]);
                foreach ($lines as $line) {
                    DB::table('courier_statement_lines')->insert(['statement_id' => $id, 'shipment_id' => $line['shipment_id'], 'consignment_id' => $line['consignment'], 'courier_status' => $line['status'], 'cod_minor' => $line['cod'], 'fee_minor' => $line['fee'], 'delivered_at' => $line['delivered_at'], 'result' => $line['result'], 'created_at' => $now]);
                }
                foreach ($problems as [$type, $shipmentId, $reference, $details]) {
                    $this->issues->raise($type, $shipmentId, $reference, $details);
                }
                if ($remit !== []) {
                    $this->cash->recordStatement($actor, $courier, $statementRef, $receivedMinor, $fees, $receivedAt, $remit);
                }
                $statement = CourierStatement::query()->findOrFail($id);
                $this->audit->record('courier.statement_imported', $statement, [], ['courier' => $courier, 'statement' => $statementRef, 'lines' => count($lines), 'matched' => $matched, 'problems' => count($problems)], $actor);

                return $statement;
            });
        } catch (UniqueConstraintViolationException) {
            throw new ShipmentException('statement_already_imported'); // someone checked this very file a moment earlier
        } catch (DomainException $e) {
            // the cash side refused: another statement covered these parcels in the same instant, or the statement number was taken there
            throw new ShipmentException($e->getMessage() === 'cod_already_remitted' ? 'statement_conflict' : 'statement_already_imported');
        }

        return $statement;
    }

    /**
     * @return array<string, array{status: string, cod: int, fee: int, delivered_at: ?string}> keyed by consignment id
     *
     * @throws ShipmentException
     */
    private function parse(string $csv): array
    {
        $lines = explode("\n", $csv);
        $header = array_map(fn (?string $c): string => strtolower(trim((string) $c)), str_getcsv((string) array_shift($lines), ',', '"', ''));
        if ($header !== self::HEADER) {
            throw new ShipmentException('statement_invalid', ['errors' => 'line 1: the header must be '.implode(',', self::HEADER)]);
        }
        if (count($lines) > self::MAX_ROWS) {
            throw new ShipmentException('statement_too_large');
        }

        $rows = [];
        $errors = [];
        foreach ($lines as $i => $raw) {
            $line = $i + 2;
            if (trim($raw) === '') {
                continue;
            }
            $cells = array_map(fn (?string $c): string => trim((string) $c), str_getcsv($raw, ',', '"', ''));
            if (count($cells) !== 5) {
                $errors[] = "line {$line}: expected 5 columns";

                continue;
            }
            [$consignment, $status, $cod, $fee, $deliveredAt] = $cells;
            if ($consignment === '' || mb_strlen($consignment) > 80 || $status === '' || mb_strlen($status) > 60) {
                $errors[] = "line {$line}: consignment_id and status are needed";

                continue;
            }
            try {
                $codMinor = $cod === '' ? 0 : Money::fromMajorString($cod)->amountMinor;
                $feeMinor = $fee === '' ? 0 : Money::fromMajorString($fee)->amountMinor;
            } catch (\Throwable) {
                $errors[] = "line {$line}: amounts must be numbers with at most 2 decimals";

                continue;
            }
            if ($codMinor < 0 || $feeMinor < 0) {
                $errors[] = "line {$line}: amounts cannot be negative";

                continue;
            }
            $when = null;
            if ($deliveredAt !== '') {
                try {
                    $when = CarbonImmutable::parse($deliveredAt, 'UTC')->format('Y-m-d H:i:s.u');
                } catch (\Throwable) {
                    $errors[] = "line {$line}: delivered_at is not a date";

                    continue;
                }
            }
            if (isset($rows[$consignment])) {
                $errors[] = "line {$line}: {$consignment} is listed twice";

                continue;
            }
            $rows[$consignment] = ['status' => $status, 'cod' => $codMinor, 'fee' => $feeMinor, 'delivered_at' => $when];
        }

        if ($errors !== []) {
            throw new ShipmentException('statement_invalid', ['errors' => implode('; ', array_slice($errors, 0, 10)).(count($errors) > 10 ? '; …' : '')]);
        }
        if ($rows === []) {
            throw new ShipmentException('statement_invalid', ['errors' => 'the statement has no parcels']);
        }

        return $rows;
    }

    /**
     * Where the statement says "delivered" and we do not, ask the courier itself (when it can be asked), so our own state follows the courier's own answer and
     * not the file. A courier that cannot be reached is left as a mismatch for a person.
     *
     * @param  array<string, array{status: string, cod: int, fee: int, delivered_at: ?string}>  $rows
     * @param  Collection<string, Shipment>  $shipments
     */
    private function askTheCourier(string $courier, array $rows, $shipments): void
    {
        $provider = $this->couriers->for($courier);
        if (! $provider->capabilities()->track) {
            return;
        }

        foreach ($rows as $consignment => $row) {
            $shipment = $shipments->get($consignment);
            $mapped = CourierStatusMapping::query()->where('courier', $courier)->where('provider_status', $row['status'])->value('internal_status');
            if ($shipment === null || $mapped !== 'delivered' || $shipment->status->value === 'delivered' || $shipment->status->isFinal()) {
                continue;
            }
            try {
                foreach ($provider->track($consignment) as $event) {
                    $this->record->handle($courier, $event, 'poll');
                }
            } catch (CourierException) {
                // cannot be reached now: the mismatch is raised for a person
            }
        }
    }
}
