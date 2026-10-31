<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Models\User;
use App\Modules\Payments\Database\Seeders\PaymentMethodSeeder;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/*
 * Orders and accounts under REAL concurrency (Phase 6 gate): every probe is its own PHP process with its own MariaDB connection, all released at
 * the same instant. Rules proved: a guest order goes into exactly one account, and an order is cancelled once.
 */

beforeEach(function () {
    $this->seed([LanguageSeeder::class, PaymentMethodSeeder::class]);
    $this->users = collect(range(1, 6))->map(fn ($i) => User::factory()->create(['email' => "u{$i}@example.com"]));
    $this->order = function (int $id, ?User $owner = null, array $over = []): string {
        fakeOrders([$id], array_replace(['user_id' => $owner?->id], $over));

        return (string) DB::table('orders')->where('id', $id)->value('public_id');
    };
});

/**
 * @param  list<list<string>>  $argLists
 * @return list<array<string, mixed>>
 */
function runAccountProbes(array $argLists): array
{
    $startAt = microtime(true) + 4.0 + count($argLists) * 0.2;
    $env = ['APP_ENV' => 'testing', 'DB_DATABASE' => 'velmora_test', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'null', 'SESSION_DRIVER' => 'array', 'APP_DEBUG' => 'false'];

    $processes = array_map(function (array $args) use ($env, $startAt) {
        $p = new Process([PHP_BINARY, 'artisan', 'orders:account-probe', ...$args, '--start-at='.$startAt], base_path(), $env);
        $p->setTimeout(180)->start();

        return $p;
    }, $argLists);

    return array_map(function (Process $p): array {
        $p->wait();
        $lines = array_values(array_filter(explode("\n", trim($p->getOutput()))));
        $decoded = json_decode((string) end($lines), true);
        expect($decoded)->toBeArray('probe produced no JSON: '.$p->getOutput().$p->getErrorOutput());

        return $decoded;
    }, $processes);
}

it('puts a guest order into exactly one account when six customers claim it at the same instant', function () {
    $order = ($this->order)(1);

    $results = runAccountProbes($this->users->map(fn (User $u) => ['link', "--order={$order}", "--user-id={$u->id}"])->all());

    $winners = array_values(array_filter($results, fn ($r) => $r['ok']));
    expect($winners)->toHaveCount(1)->and(array_unique(array_column(array_filter($results, fn ($r) => ! $r['ok']), 'reason')))->toBe(['order_already_linked'])
        ->and((int) DB::table('orders')->value('user_id'))->toBe($winners[0]['user'])->and(DB::table('audit_events')->where('action', 'order.linked_to_account')->count())->toBe(1);
});

it('lets the same customer claim the same order from two tabs without trouble', function () {
    $order = ($this->order)(1);
    $uid = $this->users[0]->id;

    $results = runAccountProbes(array_map(fn () => ['link', "--order={$order}", "--user-id={$uid}"], range(1, 5)));

    expect(array_unique(array_column($results, 'ok')))->toBe([true])->and((int) DB::table('orders')->value('user_id'))->toBe($uid)->and(DB::table('audit_events')->where('action', 'order.linked_to_account')->count())->toBe(1);
});

it('cancels an order once when the owner presses cancel in five tabs at the same instant', function () {
    $owner = $this->users[0];
    $order = ($this->order)(1, $owner);

    $results = runAccountProbes(array_map(fn () => ['cancel', "--order={$order}", "--user-id={$owner->id}"], range(1, 5)));

    expect(count(array_filter($results, fn ($r) => $r['ok'])))->toBe(1)->and(DB::table('orders')->value('status'))->toBe('cancelled')->and(DB::table('order_status_history')->where('order_id', 1)->where('to_state', 'cancelled')->count())->toBe(1);
});

it('lets nobody but the owner cancel, even all at once', function () {
    $owner = $this->users[0];
    $order = ($this->order)(1, $owner);

    $results = runAccountProbes($this->users->slice(1)->map(fn (User $u) => ['cancel', "--order={$order}", "--user-id={$u->id}"])->values()->all());

    expect(array_unique(array_column($results, 'reason')))->toBe(['order_not_found'])->and(DB::table('orders')->value('status'))->toBe('pending');
});
