<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;

/** Proves the books balance: on_hand = Σ ledger, reserved = Σ active reservations. Anything else is a bug or tampering. */
final class StockLedgerVerifier
{
    /** @return list<array{variant_id: int, location_id: int, kind: string, expected: int, actual: int}> */
    public function verify(): array
    {
        $out = [];

        $onHand = DB::select('SELECT l.variant_id, l.location_id, l.on_hand AS actual, COALESCE(m.s, 0) AS expected
              FROM stock_levels l
              LEFT JOIN (SELECT variant_id, location_id, SUM(delta) AS s FROM stock_movements GROUP BY variant_id, location_id) m
                ON m.variant_id = l.variant_id AND m.location_id = l.location_id
             WHERE l.on_hand <> COALESCE(m.s, 0)');

        foreach ($onHand as $r) {
            $out[] = ['variant_id' => (int) $r->variant_id, 'location_id' => (int) $r->location_id, 'kind' => 'on_hand_vs_ledger', 'expected' => (int) $r->expected, 'actual' => (int) $r->actual];
        }

        // movements that have no level row at all
        $orphans = DB::select('SELECT m.variant_id, m.location_id, SUM(m.delta) AS s FROM stock_movements m
              LEFT JOIN stock_levels l ON l.variant_id = m.variant_id AND l.location_id = m.location_id
             WHERE l.variant_id IS NULL GROUP BY m.variant_id, m.location_id HAVING SUM(m.delta) <> 0');

        foreach ($orphans as $r) {
            $out[] = ['variant_id' => (int) $r->variant_id, 'location_id' => (int) $r->location_id, 'kind' => 'ledger_without_level', 'expected' => 0, 'actual' => (int) $r->s];
        }

        $reserved = DB::select("SELECT l.variant_id, l.location_id, l.reserved AS actual, COALESCE(r.q, 0) AS expected
              FROM stock_levels l
              LEFT JOIN (SELECT variant_id, location_id, SUM(quantity) AS q FROM stock_reservations WHERE status = 'active' GROUP BY variant_id, location_id) r
                ON r.variant_id = l.variant_id AND r.location_id = l.location_id
             WHERE l.reserved <> COALESCE(r.q, 0)");

        foreach ($reserved as $r) {
            $out[] = ['variant_id' => (int) $r->variant_id, 'location_id' => (int) $r->location_id, 'kind' => 'reserved_vs_reservations', 'expected' => (int) $r->expected, 'actual' => (int) $r->actual];
        }

        return $out;
    }
}
