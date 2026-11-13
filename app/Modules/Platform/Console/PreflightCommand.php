<?php

declare(strict_types=1);

namespace App\Modules\Platform\Console;

use App\Modules\Platform\Support\Preflight;
use Illuminate\Console\Command;

/** Can this server run the shop? Exit code 1 when anything fails (warnings do not fail it). */
final class PreflightCommand extends Command
{
    protected $signature = 'velmora:preflight';

    protected $description = 'Check this server (PHP, database, permissions, cron, queue) before relying on it';

    public function handle(Preflight $preflight): int
    {
        $results = $preflight->run();
        $label = ['ok' => 'OK  ', 'warn' => 'WARN', 'fail' => 'FAIL'];
        foreach ($results as $r) {
            $this->line(sprintf('%s  %-34s %s', $label[$r['status']] ?? '?   ', $r['name'], $r['detail']));
        }
        $fails = count(array_filter($results, fn (array $r): bool => $r['status'] === 'fail'));
        $warns = count(array_filter($results, fn (array $r): bool => $r['status'] === 'warn'));
        $fails === 0 ? $this->info("No failures ({$warns} to read).") : $this->error("{$fails} failure(s): this server cannot run the shop yet.");

        return $fails === 0 ? self::SUCCESS : self::FAILURE;
    }
}
