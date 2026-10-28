<?php

declare(strict_types=1);

use App\Modules\Platform\Support\AppendOnly;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

afterEach(fn () => Schema::dropIfExists('tmp_append_only'));

function makeTmpLedger(array $mutable = []): void
{
    Schema::dropIfExists('tmp_append_only');
    Schema::create('tmp_append_only', function ($table) {
        $table->id();
        $table->string('amount_note');
        $table->string('status')->default('initiated');
        $table->nullableTimestamps();
    });
    AppendOnly::protect('tmp_append_only', $mutable);
}

it('blocks UPDATE and DELETE entirely when no columns are mutable', function () {
    makeTmpLedger();
    DB::table('tmp_append_only')->insert(['amount_note' => '100']);

    expect(fn () => DB::table('tmp_append_only')->update(['status' => 'x']))->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::table('tmp_append_only')->delete())->toThrow(QueryException::class, 'append-only');
});

it('lets only the named status columns change', function () {
    makeTmpLedger(['status', 'updated_at']);
    DB::table('tmp_append_only')->insert(['amount_note' => '100']);

    DB::table('tmp_append_only')->update(['status' => 'succeeded', 'updated_at' => now()]);
    expect(DB::table('tmp_append_only')->value('status'))->toBe('succeeded');

    expect(fn () => DB::table('tmp_append_only')->update(['amount_note' => '999']))->toThrow(QueryException::class, 'only status columns may change')
        ->and(DB::table('tmp_append_only')->value('amount_note'))->toBe('100');

    expect(fn () => DB::table('tmp_append_only')->delete())->toThrow(QueryException::class, 'append-only');
});

it('rejects unsafe identifiers', function () {
    AppendOnly::protect('bad name; drop table x');
})->throws(InvalidArgumentException::class);
