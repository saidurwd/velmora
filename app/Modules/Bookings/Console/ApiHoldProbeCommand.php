<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

/**
 * Test probe: sends one POST /api/v1/services/{sku}/hold through the whole HTTP stack (middleware, throttles, controller, Action) inside its own process,
 * so concurrency tests exercise the same path an app does. `--start-at` holds the start so many probes can be released at the same instant. Prints one JSON line.
 */
final class ApiHoldProbeCommand extends Command
{
    protected $signature = 'bookings:api-hold-probe {sku : the service sku} {resource : the person or room (public id)} {start : the start time} {cartToken : the guest cart token} {--start-at= : wait until this Unix time (with decimals)}';

    protected $description = 'Test probe: hold one time through the API (concurrency tests only)';

    public function handle(Kernel $kernel): int
    {
        $startAt = $this->option('start-at');
        if (is_string($startAt) && $startAt !== '') {
            usleep((int) max(0, ((float) $startAt - microtime(true)) * 1_000_000));
        }

        $request = Request::create('/api/v1/services/'.$this->argument('sku').'/hold', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_CART_TOKEN' => (string) $this->argument('cartToken'), 'REMOTE_ADDR' => '127.0.0.1',
        ], (string) json_encode(['resource' => $this->argument('resource'), 'start' => $this->argument('start')]));

        $response = $kernel->handle($request);
        $body = json_decode((string) $response->getContent(), true);

        $this->line(json_encode([
            'status' => $response->getStatusCode(),
            'code' => is_array($body) ? ($body['code'] ?? null) : null,
            'booking' => is_array($body) ? ($body['data']['id'] ?? null) : null,
        ]) ?: '{}');

        return self::SUCCESS;
    }
}
