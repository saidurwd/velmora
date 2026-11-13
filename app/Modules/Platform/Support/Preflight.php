<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Checks that THIS server can run the shop, before anyone depends on it. Run it after every move to a new server or hosting plan (`php artisan velmora:preflight`).
 * Each line is ok, warn (works, but read it) or fail (will not work or is unsafe). The only thing it writes is a few tiny tables with a random name, dropped again at once,
 * to prove the database account may create the things the schema needs.
 */
final class Preflight
{
    private const EXTENSIONS = ['mbstring', 'intl', 'pdo_mysql', 'gd', 'zip', 'bcmath', 'openssl', 'fileinfo', 'ctype', 'curl', 'xml', 'json'];

    /** @return list<array{name: string, status: string, detail: string}> */
    public function run(): array
    {
        $out = [];
        $add = function (string $name, string $status, string $detail) use (&$out): void {
            $out[] = ['name' => $name, 'status' => $status, 'detail' => $detail];
        };

        $add('PHP version', version_compare(PHP_VERSION, '8.3.0', '>=') ? 'ok' : 'fail', PHP_VERSION.' (8.3 or newer needed)');
        $missing = array_values(array_filter(self::EXTENSIONS, fn (string $e): bool => ! extension_loaded($e)));
        $add('PHP extensions', $missing === [] ? 'ok' : 'fail', $missing === [] ? 'all present' : 'missing: '.implode(', ', $missing));
        $add('App key', config('app.key') ? 'ok' : 'fail', config('app.key') ? 'set' : 'APP_KEY is empty (php artisan key:generate)');

        $dbReachable = true;
        try {
            $reported = (string) DB::scalar('select version()');
            $ok = MariaDbVersion::isSupported($reported);
            $add('Database engine', $ok ? 'ok' : 'fail', $reported.' (MariaDB '.MariaDbVersion::MINIMUM.' or newer needed)');
        } catch (Throwable $e) {
            $dbReachable = false;
            $add('Database connection', 'fail', 'cannot connect: '.$e::class);
        }
        if ($dbReachable) {
            array_push($out, ...$this->databaseAbilities());
        }

        foreach (['storage/logs', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'bootstrap/cache'] as $dir) {
            $add('Writable: '.$dir, is_writable(base_path($dir)) ? 'ok' : 'fail', is_writable(base_path($dir)) ? 'yes' : 'the web server cannot write here');
        }
        $built = is_file(public_path('build/manifest.json'));
        $add('Built assets', $built ? 'ok' : 'fail', $built ? 'public/build is present' : 'public/build/manifest.json is missing (build the assets before uploading)');

        foreach (['media' => 'Product images', 'private' => 'Private files'] as $disk => $what) {
            $add('File storage: '.$what, ...$this->diskProbe($disk));
        }

        $production = app()->environment('production');
        $cache = (string) config('cache.default');
        $add('Cache store', $cache === 'array' ? 'fail' : 'ok', $cache.($cache === 'array' ? ' (forgets everything on each request)' : ''));
        $queue = (string) config('queue.default');
        $fromScheduler = (bool) config('queue.run_from_scheduler');
        $add('Queue', $queue === 'sync' && $production ? 'warn' : (($queue === 'database' && ! $fromScheduler) ? 'warn' : 'ok'), match (true) {
            $queue === 'sync' => 'sync: jobs run inside the visitor\'s request (slow checkout); use database with QUEUE_RUN_FROM_SCHEDULER=true on shared hosting',
            $queue === 'database' && ! $fromScheduler => 'database: needs a worker, or set QUEUE_RUN_FROM_SCHEDULER=true so the once-a-minute scheduler drains it',
            default => $queue.($fromScheduler ? ' (drained by the scheduler every minute)' : ''),
        });

        $beat = Cache::get('scheduler:heartbeat');
        $age = is_string($beat) ? now()->diffInSeconds(CarbonImmutable::parse($beat), true) : null;
        $add('Scheduler (cron)', $age !== null && $age <= 180 ? 'ok' : 'warn', $age === null ? 'no heartbeat yet: add the cron line `* * * * * php artisan schedule:run` and run this again after two minutes' : 'last ran '.(int) $age.' s ago');

        foreach (['sms' => ['services.sms.driver', 'texts'], 'push' => ['services.push.driver', 'app push messages']] as [$key, $what]) {
            $driver = (string) config($key);
            $add('Driver: '.$what, $production && in_array($driver, ['log', 'fake'], true) ? 'warn' : 'ok', $driver.($production && in_array($driver, ['log', 'fake'], true) ? ' (nothing real is sent)' : ''));
        }

        return $out;
    }

    /** @return array{0: string, 1: string} status and detail: can the app write, read back and delete a file on this disk? */
    private function diskProbe(string $disk): array
    {
        $driver = (string) config("filesystems.disks.{$disk}.driver");
        $key = 'preflight/'.bin2hex(random_bytes(4)).'.txt';
        try {
            Storage::disk($disk)->put($key, 'ok');
            $read = Storage::disk($disk)->get($key) === 'ok';
            Storage::disk($disk)->delete($key);

            return $read ? ['ok', $driver.': can write, read and delete'] : ['fail', $driver.': wrote a file but could not read it back'];
        } catch (Throwable $e) {
            return ['fail', $driver.': '.($driver === 's3' ? 'no storage bucket reachable; on shared hosting set MEDIA_DRIVER=local and PRIVATE_DRIVER=local' : 'cannot write ('.mb_substr($e->getMessage(), 0, 100).')')];
        }
    }

    /** @return list<array{name: string, status: string, detail: string}> */
    private function databaseAbilities(): array
    {
        $probe = function (string $name, callable $do): array {
            $table = 'velmora_preflight_'.bin2hex(random_bytes(3));
            try {
                $do($table);

                return ['name' => $name, 'status' => 'ok', 'detail' => 'works'];
            } catch (Throwable $e) {
                return ['name' => $name, 'status' => 'fail', 'detail' => 'refused: '.mb_substr($e->getMessage(), 0, 140)];
            } finally {
                try {
                    DB::statement("DROP TABLE IF EXISTS `{$table}`");
                } catch (Throwable) {
                    // nothing more can be done; the table has a random name and is empty
                }
            }
        };

        return [
            $probe('Collation uca1400_ai_ci', fn (string $t) => DB::statement("CREATE TABLE `{$t}` (a VARCHAR(10)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci")),
            $probe('Time periods (booking guard)', fn (string $t) => DB::statement("CREATE TABLE `{$t}` (r INT NOT NULL, s DATETIME(6) NOT NULL, e DATETIME(6) NOT NULL, PERIOD FOR p(s, e), UNIQUE (r, p WITHOUT OVERLAPS)) ENGINE=InnoDB")),
            $probe('Triggers (append-only tables)', function (string $t): void {
                DB::statement("CREATE TABLE `{$t}` (a INT) ENGINE=InnoDB");
                DB::statement("CREATE TRIGGER `{$t}_nu` BEFORE UPDATE ON `{$t}` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'append-only'");
                DB::statement("DROP TRIGGER `{$t}_nu`");
            }),
            $probe('CHECK constraints', function (string $t): void {
                DB::statement("CREATE TABLE `{$t}` (a INT, CONSTRAINT `{$t}_c` CHECK (a > 0)) ENGINE=InnoDB");
                DB::statement("ALTER TABLE `{$t}` DROP CONSTRAINT `{$t}_c`");
            }),
        ];
    }
}
