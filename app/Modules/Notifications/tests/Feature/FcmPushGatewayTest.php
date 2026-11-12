<?php

declare(strict_types=1);

use App\Modules\Notifications\DTOs\PushMessage;
use App\Modules\Notifications\Push\FcmPushGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/* Phase 11, slice 7: the FCM adapter, against recorded-shape fixtures only (docs/vendor/fcm). It never reaches Google. */

beforeEach(function () {
    Cache::flush();
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $this->publicKey = openssl_pkey_get_details($key)['key'];
    $this->file = tempnam(sys_get_temp_dir(), 'sa');
    file_put_contents($this->file, json_encode(['type' => 'service_account', 'project_id' => 'velmora-test', 'private_key_id' => 'key-1', 'private_key' => $pem, 'client_email' => 'push@velmora-test.iam.gserviceaccount.com']));
    $this->gateway = fn () => new FcmPushGateway($this->file);
    $this->message = new PushMessage('Velmora', 'Your order VM-1 is on its way.', ['target' => 'order:01abc', 'template' => 'order.shipped.push']);
    $this->token = fn (int $expires = 3600) => ['https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.fixture', 'token_type' => 'Bearer', 'expires_in' => $expires])];
    $this->sentTo = fn () => Http::recorded()->filter(fn (array $pair) => str_contains($pair[0]->url(), 'fcm.googleapis.com'))->values();
});

afterEach(function () {
    @unlink($this->file);
});

it('sends one message in the shape the documentation gives, signed in as the service account', function () {
    Http::fake(($this->token)() + ['https://fcm.googleapis.com/*' => Http::response(['name' => 'projects/velmora-test/messages/0:123%abc'])]);

    $result = ($this->gateway)()->send('device-token', $this->message);

    expect($result->accepted)->toBeTrue()->and($result->providerReference)->toBe('projects/velmora-test/messages/0:123%abc');
    /** @var Request $request */
    $request = ($this->sentTo)()->first()[0];
    expect($request->url())->toBe('https://fcm.googleapis.com/v1/projects/velmora-test/messages:send')->and($request->method())->toBe('POST')->and($request->header('Authorization'))->toBe(['Bearer ya29.fixture'])
        ->and($request->data())->toBe(['message' => [
            'token' => 'device-token', 'notification' => ['title' => 'Velmora', 'body' => 'Your order VM-1 is on its way.'], 'data' => ['target' => 'order:01abc', 'template' => 'order.shipped.push'],
            'android' => ['priority' => 'HIGH', 'ttl' => '86400s'], 'apns' => ['headers' => ['apns-priority' => '10']],
        ]]);
});

it('proves itself with an RS256 assertion for the messaging scope, valid for at most an hour, signed by the service account key', function () {
    Http::fake(($this->token)() + ['https://fcm.googleapis.com/*' => Http::response(['name' => 'n'])]);

    ($this->gateway)()->send('device-token', $this->message);

    $auth = Http::recorded()->first(fn (array $pair) => str_contains($pair[0]->url(), 'oauth2.googleapis.com'))[0];
    parse_str($auth->body(), $form);
    [$h, $c, $s] = explode('.', $form['assertion']);
    $decode = fn (string $part) => json_decode(base64_decode(strtr($part, '-_', '+/')), true);
    $claims = $decode($c);

    expect($form['grant_type'])->toBe('urn:ietf:params:oauth:grant-type:jwt-bearer')->and($decode($h))->toBe(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'key-1'])
        ->and($claims['iss'])->toBe('push@velmora-test.iam.gserviceaccount.com')->and($claims['scope'])->toBe('https://www.googleapis.com/auth/firebase.messaging')->and($claims['aud'])->toBe('https://oauth2.googleapis.com/token')
        ->and($claims['exp'] - $claims['iat'])->toBeLessThanOrEqual(3600)->and(abs($claims['iat'] - time()))->toBeLessThan(10)
        ->and(openssl_verify($h.'.'.$c, base64_decode(strtr($s, '-_', '+/')), $this->publicKey, OPENSSL_ALGO_SHA256))->toBe(1);
});

it('asks for an access token once and reuses it for the next messages', function () {
    Http::fake(($this->token)() + ['https://fcm.googleapis.com/*' => Http::response(['name' => 'n'])]);
    $gateway = ($this->gateway)();

    $gateway->send('a', $this->message);
    $gateway->send('b', $this->message);

    expect(Http::recorded()->filter(fn (array $pair) => str_contains($pair[0]->url(), 'oauth2.googleapis.com'))->count())->toBe(1)->and(($this->sentTo)())->toHaveCount(2);
});

it('says a phone is gone when the provider says it is no longer registered', function () {
    Http::fake(($this->token)() + ['https://fcm.googleapis.com/*' => Http::response(['error' => ['code' => 404, 'status' => 'NOT_FOUND', 'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']]]], 404)]);

    $result = ($this->gateway)()->send('old', $this->message);

    expect($result->accepted)->toBeFalse()->and($result->tokenInvalid)->toBeTrue()->and($result->retryable)->toBeFalse()->and($result->error)->toBe('fcm:404:UNREGISTERED');
});

it('retries a limit, an outage and an internal error, but not a refusal', function (int $status, string $code, bool $retryable) {
    Http::fake(($this->token)() + ['https://fcm.googleapis.com/*' => Http::response(['error' => ['status' => $code]], $status)]);

    $result = ($this->gateway)()->send('t', $this->message);

    expect($result->accepted)->toBeFalse()->and($result->retryable)->toBe($retryable)->and($result->tokenInvalid)->toBeFalse()->and($result->error)->toBe("fcm:{$status}:{$code}");
})->with([[429, 'RESOURCE_EXHAUSTED', true], [503, 'UNAVAILABLE', true], [500, 'INTERNAL', true], [400, 'INVALID_ARGUMENT', false], [403, 'PERMISSION_DENIED', false]]);

it('makes a new access token after the provider does not accept the stored one', function () {
    Http::fake(($this->token)() + ['https://fcm.googleapis.com/*' => Http::response(['error' => ['status' => 'UNAUTHENTICATED']], 401)]);
    $gateway = ($this->gateway)();

    $first = $gateway->send('t', $this->message);
    $gateway->send('t', $this->message);

    expect($first->retryable)->toBeTrue()->and(Http::recorded()->filter(fn (array $pair) => str_contains($pair[0]->url(), 'oauth2.googleapis.com'))->count())->toBe(2);
});

it('retries when the provider cannot be reached or gives no access token', function () {
    Http::fake(['https://oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant'], 400)]);
    $noToken = ($this->gateway)()->send('t', $this->message);

    Cache::flush();
    Http::fake(['https://oauth2.googleapis.com/*' => fn () => throw new ConnectionException('timeout')]);
    $unreachable = ($this->gateway)()->send('t', $this->message);

    expect($noToken->retryable)->toBeTrue()->and($noToken->error)->toBe('fcm:no_access_token')->and($unreachable->retryable)->toBeTrue()->and(($this->sentTo)())->toHaveCount(0);
});

it('refuses a message that is too big without sending it', function () {
    Http::fake(($this->token)());

    $result = ($this->gateway)()->send('t', new PushMessage('Velmora', str_repeat('x', 5000)));

    expect($result->accepted)->toBeFalse()->and($result->retryable)->toBeFalse()->and($result->error)->toBe('fcm:message_too_big')->and(($this->sentTo)())->toHaveCount(0);
});

it('does nothing, and says why, when the service account is missing or is not one', function (string $setup) {
    Http::fake();
    $gateway = match ($setup) {
        'missing' => new FcmPushGateway('/nonexistent/file.json'),
        'empty setting' => new FcmPushGateway(''),
        'not json' => (function () {
            file_put_contents($this->file, 'nope');

            return ($this->gateway)();
        })->call($this),
        'no key' => (function () {
            file_put_contents($this->file, json_encode(['project_id' => 'x']));

            return ($this->gateway)();
        })->call($this),
    };

    $result = $gateway->send('t', $this->message);

    expect($result->error)->toBe('fcm:not_configured')->and($result->retryable)->toBeTrue();
    Http::assertNothingSent();
})->with(['missing', 'empty setting', 'not json', 'no key']);

it('never puts the push token, the words or the key in an error', function () {
    Http::fake(($this->token)() + ['https://fcm.googleapis.com/*' => Http::response(['error' => ['status' => 'INVALID_ARGUMENT', 'message' => 'bad token device-token with Your order']], 400)]);

    $result = ($this->gateway)()->send('device-token', $this->message);

    expect((string) $result->error)->not->toContain('device-token')->not->toContain('Your order')->not->toContain('PRIVATE KEY');
});
