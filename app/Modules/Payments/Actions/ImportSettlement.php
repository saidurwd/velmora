<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Payments\Enums\TransactionStatus;
use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\SettlementImport;
use App\Modules\Payments\Services\GatewayRegistry;
use App\Modules\Payments\Services\PaymentExceptions;
use App\Modules\Payments\Support\ProviderAmount;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Transactions;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Checks a provider's settlement file against our own records. The file is a plain CSV with the header `reference,bank_txn_id,amount,currency,settled_at`
 * (one of reference / bank_txn_id may be empty; amount like 1500.00). It is NOT a provider-specific format: no saved provider document describes one yet.
 *
 *  - The whole file is refused if any line is malformed (nothing half-checked), and the same file is checked once (UNIQUE on its hash).
 *  - A line we do not know, a line whose amount/currency/bank id differs from our paid attempt, and one of OUR paid attempts in the stated period that
 *    the file does not list each become a reconciliation exception (once per problem).
 *  - A file never makes a payment paid. If it says paid while we do not, the gateway is asked (server to server); only its answer can change anything.
 * Who may import (permission `payments.reconcile`) is decided by the caller.
 */
final class ImportSettlement
{
    public const MAX_BYTES = 2_000_000;

    public const MAX_ROWS = 20_000;

    public const MAX_PERIOD_DAYS = 31;

    private const HEADER = ['reference', 'bank_txn_id', 'amount', 'currency', 'settled_at'];

    public function __construct(
        private readonly GatewayRegistry $gateways,
        private readonly VerifyPayment $verify,
        private readonly PaymentExceptions $exceptions,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {}

    /** @throws PaymentException */
    public function handle(Actor $actor, string $provider, string $csv, CarbonImmutable $from, CarbonImmutable $to): SettlementImport
    {
        if (! $this->gateways->has($provider)) {
            throw new PaymentException('unknown_provider');
        }
        if ($to->lessThan($from) || $from->diffInDays($to) > self::MAX_PERIOD_DAYS || $to->greaterThan($this->clock->now()->addMinutes(5))) {
            throw new PaymentException('settlement_window_invalid', ['days' => self::MAX_PERIOD_DAYS]);
        }
        if (strlen($csv) > self::MAX_BYTES) {
            throw new PaymentException('settlement_file_too_large');
        }

        $normalised = trim(str_replace(["\r\n", "\r"], "\n", preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv));
        $hash = hash('sha256', $normalised);
        if (SettlementImport::query()->where('provider', $provider)->where('file_hash', $hash)->exists()) {
            throw new PaymentException('settlement_already_imported');
        }

        $rows = $this->parse($normalised);

        $matched = 0;
        $problems = 0;
        $seenRefs = [];
        $seenBank = [];
        foreach ($rows as $row) {
            $tx = $this->find($provider, $row);
            if ($tx !== null && $tx->status !== TransactionStatus::Succeeded) {
                $this->verify->handle($tx->merchant_ref); // the file is not proof: ask the gateway, and use only its answer
                $tx = PaymentTransaction::query()->find($tx->id);
            }

            if ($tx !== null) {
                $seenRefs[$tx->merchant_ref] = true;
                if ($tx->provider_txn_id !== null) {
                    $seenBank[$tx->provider_txn_id] = true;
                }
            }
            $problem = $this->check($provider, $row, $tx);
            $problems += $problem ? 1 : 0;
            $matched += $problem ? 0 : 1;
        }

        $problems += $this->missingFromFile($provider, $from, $to, $seenRefs, $seenBank);

        try {
            $import = Transactions::run(function () use ($actor, $provider, $hash, $from, $to, $rows, $matched, $problems): SettlementImport {
                $import = SettlementImport::query()->create([
                    'public_id' => strtolower((string) Str::ulid()), 'provider' => $provider, 'file_hash' => $hash, 'period_from' => $from, 'period_to' => $to,
                    'row_count' => count($rows), 'matched_count' => $matched, 'problem_count' => $problems, 'imported_by' => $actor->id, 'created_at' => $this->clock->now(),
                ]);
                $this->audit->record('settlement.imported', $import, [], ['provider' => $provider, 'rows' => count($rows), 'matched' => $matched, 'problems' => $problems], $actor);

                return $import;
            });
        } catch (UniqueConstraintViolationException) {
            throw new PaymentException('settlement_already_imported'); // someone checked this very file a moment earlier (their exceptions are the same ones)
        }

        return $import;
    }

    /**
     * @return list<array{line: int, reference: string, bank_txn_id: string, amount_minor: int, currency: string, settled_at: string}>
     *
     * @throws PaymentException
     */
    private function parse(string $csv): array
    {
        $lines = explode("\n", $csv);
        $header = array_map(fn (?string $c): string => strtolower(trim((string) $c)), str_getcsv((string) array_shift($lines), ',', '"', ''));
        if ($header !== self::HEADER) {
            throw new PaymentException('settlement_file_invalid', ['errors' => 'line 1: the header must be '.implode(',', self::HEADER)]);
        }
        if (count($lines) > self::MAX_ROWS) {
            throw new PaymentException('settlement_file_too_large');
        }

        $rows = [];
        $errors = [];
        $seen = [];
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
            [$reference, $bank, $amount, $currency, $settledAt] = $cells;
            $currency = strtoupper($currency);
            if ($reference === '' && $bank === '') {
                $errors[] = "line {$line}: reference or bank_txn_id is needed";

                continue;
            }
            if (mb_strlen($reference) > 64 || mb_strlen($bank) > 80) {
                $errors[] = "line {$line}: reference or bank_txn_id is too long";

                continue;
            }
            if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                $errors[] = "line {$line}: currency must be 3 letters";

                continue;
            }
            try {
                $minor = ProviderAmount::toMoney($amount, $currency)->amountMinor;
            } catch (PaymentException) {
                $errors[] = "line {$line}: amount must be a number with at most 2 decimals";

                continue;
            }
            if ($minor < 1) {
                $errors[] = "line {$line}: amount must be more than zero";

                continue;
            }
            try {
                $when = CarbonImmutable::parse($settledAt, 'UTC')->toIso8601String();
            } catch (\Throwable) {
                $errors[] = "line {$line}: settled_at is not a date";

                continue;
            }
            $key = $bank !== '' ? 'b:'.$bank : 'r:'.$reference;
            if (isset($seen[$key])) {
                $errors[] = "line {$line}: listed twice (also line {$seen[$key]})";

                continue;
            }
            $seen[$key] = $line;
            $rows[] = ['line' => $line, 'reference' => $reference, 'bank_txn_id' => $bank, 'amount_minor' => $minor, 'currency' => $currency, 'settled_at' => $when];
        }

        if ($errors !== []) {
            throw new PaymentException('settlement_file_invalid', ['errors' => implode('; ', array_slice($errors, 0, 10)).(count($errors) > 10 ? '; …' : '')]);
        }
        if ($rows === []) {
            throw new PaymentException('settlement_file_invalid', ['errors' => 'the file has no payments']);
        }

        return $rows;
    }

    /** @param  array{reference: string, bank_txn_id: string}  $row */
    private function find(string $provider, array $row): ?PaymentTransaction
    {
        if ($row['bank_txn_id'] !== '') {
            $tx = PaymentTransaction::query()->where('provider', $provider)->where('provider_txn_id', $row['bank_txn_id'])->first();
            if ($tx !== null) {
                return $tx;
            }
        }

        return $row['reference'] === '' ? null : PaymentTransaction::query()->where('provider', $provider)->where('merchant_ref', $row['reference'])->first();
    }

    /**
     * Raises what is wrong with one line. Returns true when something was raised.
     *
     * @param  array{line: int, reference: string, bank_txn_id: string, amount_minor: int, currency: string, settled_at: string}  $row
     */
    private function check(string $provider, array $row, ?PaymentTransaction $tx): bool
    {
        $label = $row['bank_txn_id'] !== '' ? $row['bank_txn_id'] : $row['reference'];
        $file = ['provider' => $provider, 'line' => $row['line'], 'reference' => $row['reference'], 'bank_txn_id' => $row['bank_txn_id'], 'amount_minor' => $row['amount_minor'], 'currency' => $row['currency'], 'settled_at' => $row['settled_at']];

        if ($tx === null) {
            $this->exceptions->raise('unmatched_provider_txn', null, null, $label, $file + ['reason' => 'not_ours']);

            return true;
        }
        if ($tx->status !== TransactionStatus::Succeeded) {
            $this->exceptions->raise('unmatched_provider_txn', $tx->payment_id, null, $label, $file + ['reason' => 'file_says_paid_we_do_not', 'our_status' => $tx->status->value, 'merchant_ref' => $tx->merchant_ref]);

            return true;
        }
        if ($row['bank_txn_id'] !== '' && $tx->provider_txn_id !== null && $tx->provider_txn_id !== $row['bank_txn_id']) {
            $this->exceptions->raise('unmatched_provider_txn', $tx->payment_id, null, $label, $file + ['reason' => 'bank_id_differs', 'our_bank_txn_id' => $tx->provider_txn_id, 'merchant_ref' => $tx->merchant_ref]);

            return true;
        }
        if ($row['currency'] !== $tx->currency) {
            $this->exceptions->raise('currency_mismatch', $tx->payment_id, null, $tx->merchant_ref, $file + ['our_currency' => $tx->currency]);

            return true;
        }
        if ($row['amount_minor'] !== $tx->amount_minor) {
            $this->exceptions->raise('amount_mismatch', $tx->payment_id, null, $tx->merchant_ref, $file + ['our_amount_minor' => $tx->amount_minor]);

            return true;
        }

        return false;
    }

    /**
     * Our paid attempts verified inside the file's period that the file does not list.
     *
     * @param  array<string, true>  $seenRefs
     * @param  array<string, true>  $seenBank
     */
    private function missingFromFile(string $provider, CarbonImmutable $from, CarbonImmutable $to, array $seenRefs, array $seenBank): int
    {
        $raised = 0;
        // serves: paid attempts of one provider verified in a period (index status, created_at is too wide; the period is at most 31 days)
        $paid = PaymentTransaction::query()->where('provider', $provider)->where('status', 'succeeded')
            ->whereBetween('verified_at', [$from->format('Y-m-d H:i:s.u'), $to->format('Y-m-d H:i:s.u')])->orderBy('id')->get();
        foreach ($paid as $tx) {
            if (isset($seenRefs[$tx->merchant_ref]) || ($tx->provider_txn_id !== null && isset($seenBank[$tx->provider_txn_id]))) {
                continue;
            }
            $this->exceptions->raise('orphan_payment', $tx->payment_id, null, $tx->merchant_ref, ['provider' => $provider, 'amount_minor' => $tx->amount_minor, 'currency' => $tx->currency, 'bank_txn_id' => $tx->provider_txn_id, 'verified_at' => $tx->verified_at?->toIso8601String(), 'reason' => 'paid_here_not_in_file']);
            $raised++;
        }

        return $raised;
    }
}
