<?php

declare(strict_types=1);

use App\Modules\Payments\DTOs\PaymentIntent;
use App\Modules\Payments\Enums\VerifiedStatus;
use App\Modules\Payments\Gateways\SslCommerzGateway;
use App\Modules\Platform\DTOs\Money;
use Illuminate\Support\Facades\Http;

/*
 * Opt-in checks against the real SSLCommerz SANDBOX. See README.md in this folder. Not part of any test suite.
 */

$on = fn (): bool => getenv('SSLCOMMERZ_SANDBOX_TESTS') === '1' && str_ends_with((string) getenv('SSLCOMMERZ_SANDBOX_STORE_ID'), '@ssl') && (string) getenv('SSLCOMMERZ_SANDBOX_STORE_PASSWORD') !== '';

beforeEach(function () {
    Http::allowStrayRequests();   // these tests are the one place real HTTP is allowed: to the sandbox only
    $this->gateway = new SslCommerzGateway((string) getenv('SSLCOMMERZ_SANDBOX_STORE_ID'), (string) getenv('SSLCOMMERZ_SANDBOX_STORE_PASSWORD'), true, 'sandbox-check@example.com');
});

it('is accepted by the sandbox: asking about a reference that does not exist is answered, not refused', function () {
    $answer = $this->gateway->status('VM-SANDBOX-NOSUCH-'.bin2hex(random_bytes(4)));

    expect($answer->status)->toBe(VerifiedStatus::Unknown);   // a credential problem would have thrown provider_auth_failed
})->skip(fn () => ! $on(), 'set SSLCOMMERZ_SANDBOX_TESTS=1 and sandbox credentials (see README.md)');

it('creates a payment session and returns a page on SSLCommerz\'s own domain (no money moves)', function () {
    $ref = 'VM-SB-'.bin2hex(random_bytes(6));
    $result = $this->gateway->initiate(new PaymentIntent(
        $ref, Money::of(15000), 'VM-SB', 'Sandbox Check', '01711111111', 'sandbox-check@example.com', 'House 1, Road 1', 'Dhaka', '1205', 1, true, 'Sandbox check item',
        'https://shop.test/ok', 'https://shop.test/fail', 'https://shop.test/cancel', 'https://shop.test/ipn',
    ));

    expect($result->redirectUrl)->toStartWith('https://sandbox.sslcommerz.com/')->and($result->sessionKey)->not->toBeEmpty();
    // the session exists but is unpaid: the gateway says so (or has not recorded it yet), and never "valid"
    expect($this->gateway->status($ref)->status)->not->toBe(VerifiedStatus::Valid);
})->skip(fn () => ! $on(), 'set SSLCOMMERZ_SANDBOX_TESTS=1 and sandbox credentials (see README.md)');
