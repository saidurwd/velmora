<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Console;

use App\Modules\IdentityAccess\Actions\RotateRefreshToken;
use Illuminate\Console\Command;

/** Test probe: swaps one refresh token the way the API would, in its own process. `--start-at` holds the start so tests can release many at the same instant. Prints one JSON line. */
final class ApiRefreshProbeCommand extends Command
{
    protected $signature = 'identity:api-refresh-probe {token : the refresh token} {--start-at= : wait until this Unix time (with decimals)}';

    protected $description = 'Test probe: use a refresh token once (concurrency tests only)';

    public function handle(RotateRefreshToken $rotate): int
    {
        $startAt = $this->option('start-at');
        if (is_string($startAt) && $startAt !== '') {
            usleep((int) max(0, ((float) $startAt - microtime(true)) * 1_000_000));
        }

        $this->line(json_encode(['status' => $rotate->handle((string) $this->argument('token'))->status->value]) ?: '{}');

        return self::SUCCESS;
    }
}
