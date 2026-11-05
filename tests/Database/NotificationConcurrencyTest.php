<?php

declare(strict_types=1);

use App\Modules\Notifications\Contracts\NotificationSender;
use App\Modules\Notifications\Contracts\TemplateRegistry;
use App\Modules\Notifications\DTOs\Recipient;
use App\Modules\Notifications\DTOs\TemplateDefinition;
use App\Modules\Notifications\Sms\FakeSmsGateway;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/*
 * Message delivery under REAL concurrency: every probe is its own PHP process with its own MariaDB connection, all released at the same instant.
 * Rule proved: a recorded message is delivered by exactly one of any number of workers; the rest find it taken.
 */

beforeEach(function () {
    $this->seed(LanguageSeeder::class);
    config(['services.sms.driver' => 'fake']);
    app(TemplateRegistry::class)->register(new TemplateDefinition('race.sms', 'sms', 'transactional', ['name'], ['en' => [null, 'Hi {{ name }}']], ['name' => 'Rahim']));
});

/**
 * @param  list<int>  $ids  one probe each
 * @return list<array<string, mixed>>
 */
function runNotificationProbes(array $ids): array
{
    $startAt = microtime(true) + 4.0 + count($ids) * 0.2;
    $env = ['APP_ENV' => 'testing', 'DB_DATABASE' => 'velmora_test', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'null', 'SESSION_DRIVER' => 'array', 'APP_DEBUG' => 'false', 'SMS_DRIVER' => 'fake'];
    $processes = array_map(function (int $id) use ($env, $startAt) {
        $p = new Process([PHP_BINARY, 'artisan', 'notifications:probe', (string) $id, '--start-at='.$startAt], base_path(), $env);
        $p->setTimeout(180)->start();

        return $p;
    }, $ids);

    return array_map(function (Process $p): array {
        $p->wait();
        $lines = array_values(array_filter(explode("\n", trim($p->getOutput()))));
        $decoded = json_decode((string) end($lines), true);
        expect($decoded)->toBeArray('probe produced no JSON: '.$p->getOutput().$p->getErrorOutput());

        return $decoded;
    }, $processes);
}

it('delivers a recorded message exactly once when eight workers go for it at the same instant', function () {
    // recorded while the provider is down, so it waits; then made due now
    app(FakeSmsGateway::class)->fail = true;
    $publicId = app(NotificationSender::class)->send('race.sms', new Recipient(phone: '01712345678'), ['name' => 'Rahim'], 'race-1');
    $id = (int) DB::table('notification_logs')->where('public_id', $publicId)->value('id');
    DB::table('notification_logs')->where('id', $id)->update(['status' => 'queued', 'attempts' => 0, 'next_attempt_at' => now()->subSecond()]);

    $results = runNotificationProbes(array_fill(0, 8, $id));

    expect(array_values(array_filter($results, fn (array $r) => ! $r['ok'])))->toBe([])
        ->and(count(array_filter($results, fn (array $r) => $r['result'] === 'sent')))->toBe(1)
        ->and(count(array_filter($results, fn (array $r) => $r['result'] === 'busy')))->toBe(7)
        ->and(DB::table('notification_logs')->where('id', $id)->value('status'))->toBe('sent')
        ->and((int) DB::table('notification_logs')->where('id', $id)->value('attempts'))->toBe(1)
        ->and(DB::table('notification_logs')->where('id', $id)->value('payload'))->toBeNull();
});
