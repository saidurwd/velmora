<?php

declare(strict_types=1);

namespace App\Modules\Orders\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

/**
 * Test probe: sends one POST /api/v1/checkout/orders through the whole HTTP stack (middleware, idempotency, throttles, controller) inside its own process,
 * so concurrency tests exercise the same path an app does. `--start-at` holds the start so many probes can be released at the same instant.
 * Prints one JSON line: the status, the response code and the order id.
 */
final class ApiCheckoutProbeCommand extends Command
{
    protected $signature = 'orders:api-checkout-probe {cartToken : the guest cart token} {key : the Idempotency-Key} {body : the JSON request body} {--start-at= : wait until this Unix time (with decimals)}';

    protected $description = 'Test probe: place one order through the API (concurrency tests only)';

    public function handle(Kernel $kernel): int
    {
        $startAt = $this->option('start-at');
        if (is_string($startAt) && $startAt !== '') {
            usleep((int) max(0, ((float) $startAt - microtime(true)) * 1_000_000));
        }

        $request = Request::create('/api/v1/checkout/orders', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_CART_TOKEN' => (string) $this->argument('cartToken'), 'HTTP_IDEMPOTENCY_KEY' => (string) $this->argument('key'), 'REMOTE_ADDR' => '127.0.0.1',
        ], (string) $this->argument('body'));

        $response = $kernel->handle($request);
        $body = json_decode((string) $response->getContent(), true);

        $this->line(json_encode([
            'status' => $response->getStatusCode(),
            'code' => is_array($body) ? ($body['code'] ?? null) : null,
            'order' => is_array($body) ? ($body['data']['order']['id'] ?? null) : null,
            'replayed' => $response->headers->get('Idempotency-Replayed') === 'true',
        ]) ?: '{}');

        return self::SUCCESS;
    }
}
