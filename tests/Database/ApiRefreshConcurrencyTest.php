<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Actions\IssueApiSession;
use App\Modules\IdentityAccess\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/*
 * Refresh-token rotation under REAL concurrency (Phase 11): every probe is its own PHP process with its own MariaDB connection, all released at the same
 * instant with the SAME refresh token. Rule proved: of any number of simultaneous swaps, exactly one is renewed; the others are told it was reused
 * (or find it already ended); no two callers ever hold a working pair, and the sign-in ends (strict reuse rule, ADR P11-2).
 */

/**
 * @param  list<string>  $tokens  one refresh token per probe (repeat a token to race it against itself)
 * @return list<string> the status each probe saw
 */
function raceRefresh(array $tokens): array
{
    $startAt = microtime(true) + 4.0 + count($tokens) * 0.2;
    $env = ['APP_ENV' => 'testing', 'DB_DATABASE' => 'velmora_test', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'null', 'SESSION_DRIVER' => 'array', 'APP_DEBUG' => 'false'];

    $processes = array_map(function (string $token) use ($env, $startAt) {
        $p = new Process([PHP_BINARY, 'artisan', 'identity:api-refresh-probe', $token, '--start-at='.$startAt], base_path(), $env);
        $p->setTimeout(180)->start();

        return $p;
    }, $tokens);

    return array_map(function (Process $p): string {
        $p->wait();
        $lines = array_values(array_filter(explode("\n", trim($p->getOutput()))));
        $decoded = json_decode((string) end($lines), true);
        expect($decoded)->toBeArray('probe produced no JSON: '.$p->getOutput().$p->getErrorOutput());

        return (string) $decoded['status'];
    }, $processes);
}

it('renews a refresh token exactly once when six swaps of it start at the same instant, and ends the sign-in', function () {
    foreach (range(1, 3) as $round) {
        $user = User::factory()->verified()->create(['email' => "race{$round}@example.com", 'password' => 'Tr0ub4dor-and-3-horses']);
        $pair = app(IssueApiSession::class)->start($user, 'Pixel', 'android', 'password');

        $seen = raceRefresh(array_fill(0, 6, $pair->refreshToken));

        $renewed = array_keys(array_filter($seen, fn (string $s) => $s === 'renewed'));
        expect($renewed)->toHaveCount(1, 'round '.$round.': '.implode(',', $seen))
            ->and(array_diff($seen, ['renewed', 'reused', 'invalid']))->toBe([])
            ->and(in_array('reused', $seen, true))->toBeTrue();

        // the losers saw theft, so the sign-in is over: nothing is left alive, not even the winner's new pair
        expect(DB::table('refresh_tokens')->where('user_id', $user->id)->where('status', 'active')->count())->toBe(0)
            ->and(DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count())->toBe(0);
    }
});

it('lets two different refresh tokens of two people renew at the same time without touching each other', function () {
    $a = User::factory()->verified()->create(['email' => 'a@example.com', 'password' => 'Tr0ub4dor-and-3-horses']);
    $b = User::factory()->verified()->create(['email' => 'b@example.com', 'password' => 'Tr0ub4dor-and-3-horses']);
    $pa = app(IssueApiSession::class)->start($a, 'Pixel', 'android', 'password');
    $pb = app(IssueApiSession::class)->start($b, 'iPhone', 'ios', 'password');

    expect(raceRefresh([$pa->refreshToken, $pb->refreshToken]))->toBe(['renewed', 'renewed'])
        ->and(DB::table('refresh_tokens')->where('status', 'active')->count())->toBe(2);
});
