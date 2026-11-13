<?php

declare(strict_types=1);

namespace App\Modules\Search\Database\Seeders;

use App\Modules\Search\Services\TextNormalizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Adds the spellings in LifestyleSynonyms (panjabi / punjabi / পাঞ্জাবি ...). A pair that exists in either direction is left alone; nothing is removed. Safe to run again. */
class LifestyleSynonymsSeeder extends Seeder
{
    public int $added = 0;

    public function run(): void
    {
        $now = now('UTC');
        foreach (LifestyleSynonyms::pairs() as [$a, $b]) {
            $term = TextNormalizer::text($a);
            $synonym = TextNormalizer::text($b);
            if ($term === '' || $synonym === '' || $term === $synonym) {
                continue;
            }
            $exists = DB::table('search_synonyms')->where(fn ($q) => $q->where('term', $term)->where('synonym', $synonym))->orWhere(fn ($q) => $q->where('term', $synonym)->where('synonym', $term))->exists();
            if ($exists) {
                continue;
            }
            DB::table('search_synonyms')->insert(['term' => $term, 'synonym' => $synonym, 'created_at' => $now, 'updated_at' => $now]);
            $this->added++;
        }

        $this->command?->info("Added {$this->added} search spellings.");
    }
}
