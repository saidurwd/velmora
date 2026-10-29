<?php

declare(strict_types=1);

namespace App\Modules\Customers\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Loads divisions, districts and upazilas from Database/data/geography (source, licence and date: SOURCE.md). Safe to re-run. */
class GeographySeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            DB::table('divisions')->upsert(array_map(fn ($r) => ['id' => (int) $r[0], 'name_en' => $r[1], 'name_bn' => $r[2]], $this->rows('divisions', 4)), ['id'], ['name_en', 'name_bn']);

            // the file also carries latitude/longitude/website; they are not stored (nothing uses them, and the schema policy forbids DECIMAL columns)
            DB::table('districts')->upsert(array_map(fn ($r) => ['id' => (int) $r[0], 'division_id' => (int) $r[1], 'name_en' => $r[2], 'name_bn' => $r[3]], $this->rows('districts', 7)), ['id'], ['division_id', 'name_en', 'name_bn']);

            foreach (array_chunk($this->rows('upazilas', 5), 200) as $chunk) {
                DB::table('upazilas')->upsert(array_map(fn ($r) => ['id' => (int) $r[0], 'district_id' => (int) $r[1], 'name_en' => $r[2], 'name_bn' => $r[3]], $chunk), ['id'], ['district_id', 'name_en', 'name_bn']);
            }
        });
    }

    /** @return list<list<string>> */
    private function rows(string $file, int $columns): array
    {
        $path = __DIR__.'/../data/geography/'.$file.'.csv';
        $handle = fopen($path, 'rb') ?: throw new RuntimeException("Cannot read {$path}");
        $rows = [];

        while (($r = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if (count($r) !== $columns) {
                throw new RuntimeException("{$file}.csv: expected {$columns} columns, got ".count($r));
            }
            $rows[] = array_map(fn ($cell) => trim((string) $cell), $r);
        }
        fclose($handle);

        return $rows;
    }
}
