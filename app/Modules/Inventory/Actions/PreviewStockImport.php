<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Catalog\Contracts\VariantDirectory;
use App\Modules\Inventory\Exceptions\StockOpsException;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Platform\Support\CsvReader;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Reads a stock file and works out what it WOULD do, without changing any stock (a dry run). The file has a `sku` and a `quantity` column. In "set" mode the quantity is what is
 * on hand after the import; in "add" mode it is the change (a minus takes stock away). Every row is checked and recorded with what was found, so staff see an error report
 * before anything happens; a file with even one error cannot be applied, it has to be fixed and read again. Who may (`inventory.adjust`) is decided by the caller.
 */
final class PreviewStockImport
{
    public function __construct(private readonly VariantDirectory $variants, private readonly AuditLogger $audit, private readonly Clock $clock) {}

    /**
     * @return string the import's public id
     *
     * @throws StockOpsException location_unknown | note_required | file_unreadable | columns_missing | file_empty | too_many_rows
     */
    public function handle(Actor $actor, string $path, string $fileName, string $locationCode, string $mode, string $note): string
    {
        $locationId = DB::table('stock_locations')->where('code', $locationCode)->where('is_active', true)->value('id');   // unique code
        if ($locationId === null) {
            throw new StockOpsException('location_unknown');
        }
        $note = mb_substr(trim(BanglaText::input($note)), 0, 255);
        if ($note === '') {
            throw new StockOpsException('note_required');
        }
        $mode = $mode === 'add' ? 'add' : 'set';

        $rows = $this->read($path);
        $checked = $this->check($rows, (int) $locationId, $mode);
        $errors = count(array_filter($checked, fn (array $r): bool => $r['status'] === 'error'));
        $unchanged = count(array_filter($checked, fn (array $r): bool => $r['status'] === 'unchanged'));

        return Transactions::run(function () use ($actor, $checked, $locationId, $mode, $note, $fileName, $errors, $unchanged): string {
            $now = $this->clock->now()->format('Y-m-d H:i:s.u');
            $publicId = strtolower((string) Str::ulid());
            $id = DB::table('stock_imports')->insertGetId([
                'public_id' => $publicId, 'location_id' => $locationId, 'mode' => $mode, 'status' => 'previewed', 'file_name' => mb_substr($fileName, 0, 190), 'note' => $note,
                'row_count' => count($checked), 'error_count' => $errors, 'unchanged_count' => $unchanged, 'created_by' => $actor->id, 'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach (array_chunk($checked, 200) as $chunk) {
                DB::table('stock_import_rows')->insert(array_map(fn (array $r): array => ['import_id' => $id] + $r, $chunk));
            }
            $this->audit->record('stock.import_previewed', 'stock_import', after: ['rows' => count($checked), 'errors' => $errors, 'mode' => $mode, 'location_id' => $locationId], actor: $actor, subjectId: $id);

            return $publicId;
        });
    }

    /** @return list<array{line: int, sku: string, quantity: string}> */
    private function read(string $path): array
    {
        $max = max(1, (int) config('inventory.imports.max_rows'));
        $reader = new CsvReader;
        $rows = [];
        try {
            foreach ($reader->rows($path) as $line => $row) {
                if (! array_key_exists('sku', $row) || ! array_key_exists('quantity', $row)) {
                    throw new StockOpsException('columns_missing');
                }
                if (count($rows) >= $max) {
                    throw new StockOpsException('too_many_rows', ['max' => $max]);
                }
                $rows[] = ['line' => $line, 'sku' => trim($row['sku']), 'quantity' => trim($row['quantity'])];
            }
        } catch (RuntimeException) {
            throw new StockOpsException('file_unreadable');
        }
        if ($rows === []) {
            if (! in_array('sku', $reader->headers(), true) || ! in_array('quantity', $reader->headers(), true)) {
                throw new StockOpsException('columns_missing');
            }

            throw new StockOpsException('file_empty');
        }

        return $rows;
    }

    /**
     * @param  list<array{line: int, sku: string, quantity: string}>  $rows
     * @return list<array{line: int, sku: string, variant_id: int|null, raw_quantity: string, quantity: int|null, status: string, error_code: string|null, before_on_hand: int|null, delta: int|null}>
     */
    private function check(array $rows, int $locationId, string $mode): array
    {
        $seen = [];
        $out = [];
        foreach ($rows as $r) {
            $row = ['line' => $r['line'], 'sku' => mb_substr($r['sku'], 0, 64), 'variant_id' => null, 'raw_quantity' => mb_substr($r['quantity'], 0, 40), 'quantity' => null, 'status' => 'error', 'error_code' => null, 'before_on_hand' => null, 'delta' => null];
            $out[] = $this->judge($row, $r['quantity'], $locationId, $mode, $seen);
        }

        return $out;
    }

    /**
     * @param  array{line: int, sku: string, variant_id: int|null, raw_quantity: string, quantity: int|null, status: string, error_code: string|null, before_on_hand: int|null, delta: int|null}  $row
     * @param  array<string, true>  $seen
     * @return array{line: int, sku: string, variant_id: int|null, raw_quantity: string, quantity: int|null, status: string, error_code: string|null, before_on_hand: int|null, delta: int|null}
     */
    private function judge(array $row, string $quantity, int $locationId, string $mode, array &$seen): array
    {
        $fail = fn (string $code): array => [...$row, 'status' => 'error', 'error_code' => $code];

        if ($row['sku'] === '') {
            return $fail('sku_missing');
        }
        $key = mb_strtolower($row['sku']);
        if (isset($seen[$key])) {
            return $fail('duplicate_sku');
        }
        $seen[$key] = true;

        $variant = $this->variants->findBySku($row['sku']);   // unique sku
        if ($variant === null) {
            return $fail('sku_unknown');
        }
        $row['variant_id'] = $variant->id;
        if (! $variant->trackInventory) {
            return $fail('not_tracked');
        }

        $number = BanglaText::numeric($quantity);
        if (preg_match('/^[+-]?\d{1,9}$/', $number) !== 1) {
            return $fail('quantity_invalid');
        }
        $row['quantity'] = (int) $number;
        if ($mode === 'set' && $row['quantity'] < 0) {
            return $fail('quantity_negative');
        }

        $level = DB::table('stock_levels')->where('variant_id', $variant->id)->where('location_id', $locationId)->first(['on_hand', 'reserved']);   // primary key
        $onHand = $level === null ? 0 : (int) $level->on_hand;
        $reserved = $level === null ? 0 : (int) $level->reserved;
        $new = $mode === 'set' ? $row['quantity'] : $onHand + $row['quantity'];
        $row['before_on_hand'] = $onHand;
        $row['delta'] = $new - $onHand;

        if ($row['delta'] === 0) {
            return [...$row, 'status' => 'unchanged'];
        }
        if ($new < 0 || $new < $reserved) {
            return $fail('below_reserved');
        }

        return [...$row, 'status' => 'ok'];
    }
}
