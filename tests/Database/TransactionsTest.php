<?php

declare(strict_types=1);

use App\Modules\Platform\Support\Transactions;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('retries on deadlock a bounded number of times', function () {
    $calls = 0;

    $result = Transactions::run(function () use (&$calls) {
        $calls++;

        if ($calls < 3) {
            throw new QueryException('mariadb', 'update x', [], new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));
        }

        return 'ok';
    }, attempts: 3);

    expect($result)->toBe('ok')->and($calls)->toBe(3);
});

it('gives up after the bounded attempts', function () {
    $calls = 0;

    try {
        Transactions::run(function () use (&$calls) {
            $calls++;

            throw new QueryException('mariadb', 'update x', [], new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));
        }, attempts: 2);
    } catch (QueryException) {
    }

    expect($calls)->toBe(2);
});

it('rolls back everything when the work fails', function () {
    try {
        Transactions::run(function () {
            DB::table('settings')->insert(['scope' => 'app', 'key' => 'rolled.back', 'value' => '1', 'created_at' => now(), 'updated_at' => now()]);

            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
    }

    expect(DB::table('settings')->where('key', 'rolled.back')->exists())->toBeFalse();
});
