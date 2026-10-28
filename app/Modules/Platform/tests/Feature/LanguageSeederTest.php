<?php

declare(strict_types=1);

use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use Illuminate\Support\Facades\DB;

it('seeds English (default) and Bangla idempotently', function () {
    $this->seed(LanguageSeeder::class);
    $this->seed(LanguageSeeder::class);

    expect(DB::table('languages')->count())->toBe(2)
        ->and(DB::table('languages')->where('is_default', true)->value('code'))->toBe('en')
        ->and(DB::table('languages')->where('code', 'bn')->value('native_name'))->toBe('বাংলা');
});
