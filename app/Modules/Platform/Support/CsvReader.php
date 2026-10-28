<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use Generator;
use RuntimeException;

/**
 * Streams a UTF-8 CSV (Excel's BOM tolerated) as header-keyed rows without loading the file into memory.
 * Cells are NFC-normalised and stripped of control characters; a file that is not valid UTF-8 is refused outright
 * (a silently mangled Bangla name is worse than a clear error).
 */
final class CsvReader
{
    public const MAX_ROWS = 5000;

    /** @var list<string> */
    private array $headers = [];

    /** @return list<string> lower-cased header names; available after the first iteration step */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * @return Generator<int, array<string, string>> line number (1-based, header = 1) => row
     *
     * @throws RuntimeException
     */
    public function rows(string $path): Generator
    {
        $handle = fopen($path, 'rb') ?: throw new RuntimeException('The file cannot be read.');

        try {
            $first = fgets($handle);
            if ($first === false) {
                throw new RuntimeException('The file is empty.');
            }
            rewind($handle);
            if (str_starts_with($first, "\xEF\xBB\xBF")) {
                fseek($handle, 3);
            }

            $header = fgetcsv($handle, 0, ',', '"', '');
            if ($header === false || $header === [null]) {
                throw new RuntimeException('The file has no header row.');
            }
            $this->headers = array_map(fn ($h) => strtolower(trim(self::clean((string) $h))), $header);

            $line = 1;
            $count = 0;
            while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $line++;
                if ($cells === [null]) {
                    continue; // blank line
                }
                if (++$count > self::MAX_ROWS) {
                    throw new RuntimeException('The file has more than '.self::MAX_ROWS.' rows; split it into smaller files.');
                }

                $row = [];
                foreach ($this->headers as $i => $name) {
                    $row[$name] = self::clean((string) ($cells[$i] ?? ''));
                }

                yield $line => $row;
            }
        } finally {
            fclose($handle);
        }
    }

    private static function clean(string $cell): string
    {
        if (! mb_check_encoding($cell, 'UTF-8')) {
            throw new RuntimeException('The file is not UTF-8. Save it as "CSV UTF-8" and try again.');
        }

        return BanglaText::input($cell);
    }
}
