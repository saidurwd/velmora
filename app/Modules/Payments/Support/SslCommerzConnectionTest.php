<?php

declare(strict_types=1);

namespace App\Modules\Payments\Support;

use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Gateways\SslCommerzGateway;
use App\Modules\Platform\Contracts\IntegrationTest;
use App\Modules\Platform\DTOs\TestOutcome;
use App\Modules\Platform\Support\IntegrationCredentials;
use Illuminate\Support\Str;

/**
 * Checks the saved SSLCommerz credentials against the SANDBOX with the documented read-only transaction query for a made-up transaction id.
 * It never runs against the live bank, moves no money and creates nothing there.
 */
final class SslCommerzConnectionTest implements IntegrationTest
{
    public function __construct(private readonly IntegrationCredentials $credentials) {}

    public function provider(): string
    {
        return 'sslcommerz';
    }

    public function run(): TestOutcome
    {
        $c = $this->credentials->sslcommerz();
        if (trim((string) $c['store_id']) === '' || (string) $c['store_password'] === '') {
            return new TestOutcome('not_set', false);
        }
        if (! $c['sandbox']) {
            return new TestOutcome('live_untouched', false); // the server is in live mode: the test is not run against the real bank
        }

        try {
            (new SslCommerzGateway((string) $c['store_id'], (string) $c['store_password'], true, (string) (config('mail.from.address') ?: 'noreply@example.com')))->status('conn-test-'.Str::lower(Str::random(12)));
        } catch (PaymentException $e) {
            return new TestOutcome(match ($e->getMessage()) {
                'provider_auth_failed' => 'rejected',
                'provider_inactive' => 'inactive',
                'provider_invalid_request' => 'invalid',
                default => 'unreachable',
            }, false);
        }

        return new TestOutcome('accepted', true);
    }
}
