<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * BD-23 / ADR-007: append-only tables reject UPDATE and DELETE at the database level.
 * Tables that must still change a few columns (e.g. a status) name them in $mutableColumns.
 */
final class AppendOnly
{
    /** @param  list<string>  $mutableColumns  columns that may change; everything else is frozen */
    public static function protect(string $table, array $mutableColumns = []): void
    {
        self::assertIdentifier($table);

        DB::unprepared(/** @phpstan-ignore argument.type (identifiers validated by assertIdentifier) */ "DROP TRIGGER IF EXISTS `{$table}_no_delete`");
        DB::unprepared(/** @phpstan-ignore argument.type (identifiers validated by assertIdentifier) */ "DROP TRIGGER IF EXISTS `{$table}_no_update`");

        DB::unprepared(/** @phpstan-ignore argument.type (identifiers validated by assertIdentifier) */ "CREATE TRIGGER `{$table}_no_delete` BEFORE DELETE ON `{$table}` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'append-only table: DELETE is not allowed'");

        if ($mutableColumns === []) {
            DB::unprepared(/** @phpstan-ignore argument.type (identifiers validated by assertIdentifier) */ "CREATE TRIGGER `{$table}_no_update` BEFORE UPDATE ON `{$table}` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'append-only table: UPDATE is not allowed'");

            return;
        }

        $columns = array_map(
            fn ($row) => (string) $row->COLUMN_NAME,
            DB::select('select COLUMN_NAME from information_schema.columns where table_schema = database() and table_name = ?', [$table]),
        );

        $frozen = array_values(array_diff($columns, $mutableColumns));
        $conditions = implode(' OR ', array_map(function (string $column): string {
            self::assertIdentifier($column);

            return "NOT (OLD.`{$column}` <=> NEW.`{$column}`)";
        }, $frozen));

        DB::unprepared(/** @phpstan-ignore argument.type (identifiers validated by assertIdentifier) */ "CREATE TRIGGER `{$table}_no_update` BEFORE UPDATE ON `{$table}` FOR EACH ROW BEGIN IF {$conditions} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'append-only table: only status columns may change'; END IF; END");
    }

    private static function assertIdentifier(string $name): void
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new InvalidArgumentException("Unsafe identifier [{$name}].");
        }
    }
}
