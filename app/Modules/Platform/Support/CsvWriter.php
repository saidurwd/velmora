<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

/** Writes CSV for Excel: UTF-8 with BOM (so Bangla opens correctly) and spreadsheet-formula neutralisation. */
final class CsvWriter
{
    /** @param  resource  $out */
    public static function bom($out): void
    {
        fwrite($out, "\xEF\xBB\xBF");
    }

    /**
     * @param  resource  $out
     * @param  list<string|int|null>  $cells
     * @param  list<int>  $numericColumns  column indexes that hold numbers and must stay untouched
     */
    public static function row($out, array $cells, array $numericColumns = []): void
    {
        $safe = [];
        foreach ($cells as $i => $cell) {
            $safe[] = in_array($i, $numericColumns, true) ? (string) $cell : self::neutralise((string) $cell);
        }

        fputcsv($out, $safe, ',', '"', '');
    }

    /** A cell opening with = + - @ (or tab/CR) can run as a formula when the file is opened in a spreadsheet. */
    public static function neutralise(string $cell): string
    {
        return $cell !== '' && strpbrk($cell[0], "=+-@\t\r") !== false ? "'".$cell : $cell;
    }
}
