<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Makes the database itself refuse a status change that the state machine does not allow (a paid payment going back to
 * pending, a failed refund succeeding), and a "set once" column (the bank transaction id) being replaced. The allowed moves are
 * written into each migration on purpose: the trigger is a snapshot of the rules when it was made, and a test compares it with the
 * enums so the two cannot drift apart unnoticed.
 */
final class StatusFlow
{
    /**
     * @param  array<string, list<string>>  $flow  from-status => allowed to-statuses (a status not listed is final)
     * @param  list<string>  $setOnce  columns that may go from NULL to a value, and never change after
     */
    public static function install(string $table, string $column, array $flow, array $setOnce = []): void
    {
        self::assertIdentifier($table);
        self::assertIdentifier($column);

        $moves = [];
        foreach ($flow as $from => $tos) {
            self::assertWord($from);
            array_map(self::assertWord(...), $tos);
            $moves[] = "(OLD.`{$column}` = '{$from}' AND NEW.`{$column}` IN ('".implode("','", $tos)."'))";
        }
        $allowed = $moves === [] ? 'FALSE' : implode(' OR ', $moves);

        $guards = '';
        foreach ($setOnce as $col) {
            self::assertIdentifier($col);
            $guards .= "IF OLD.`{$col}` IS NOT NULL AND NOT (NEW.`{$col}` <=> OLD.`{$col}`) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$col} is set once and cannot change'; END IF; ";
        }

        self::remove($table);
        DB::unprepared(/** @phpstan-ignore argument.type (identifiers and words validated above) */ "CREATE TRIGGER `{$table}_status_flow` BEFORE UPDATE ON `{$table}` FOR EACH ROW BEGIN "
            ."IF NOT (NEW.`{$column}` <=> OLD.`{$column}`) AND NOT ({$allowed}) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'status change not allowed'; END IF; {$guards}END");
    }

    public static function remove(string $table): void
    {
        self::assertIdentifier($table);

        DB::unprepared(/** @phpstan-ignore argument.type (identifier validated) */ "DROP TRIGGER IF EXISTS `{$table}_status_flow`");
    }

    private static function assertIdentifier(string $name): void
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
            throw new InvalidArgumentException("Unsafe identifier [{$name}].");
        }
    }

    private static function assertWord(string $word): void
    {
        if (preg_match('/^[a-z_]+$/', $word) !== 1) {
            throw new InvalidArgumentException("Unsafe status [{$word}].");
        }
    }
}
