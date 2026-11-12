<?php

declare(strict_types=1);

use App\Modules\Platform\Support\Api\PostmanCollection;

/* Phase 11, slice 8: the Postman / Bruno collection is made from the document, so it cannot disagree with it. */

beforeEach(function () {
    $this->doc = openApiDocument();
    $this->items = function () {
        $out = [];
        foreach (json_decode((string) file_get_contents(base_path('docs/api/velmora.postman_collection.json')), true)['item'] as $folder) {
            foreach ($folder['item'] as $item) {
                $out[$item['request']['method'].' '.$item['request']['url']['raw']] = $item;
            }
        }

        return $out;
    };
});

it('is the collection the document produces today (regenerate with php artisan api:collection)', function () {
    $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    $maker = new PostmanCollection;

    expect(file_get_contents(base_path('docs/api/velmora.postman_collection.json')))->toBe(json_encode($maker->collection($this->doc), $flags)."\n")
        ->and(file_get_contents(base_path('docs/api/velmora.postman_environment.json')))->toBe(json_encode($maker->environment(), $flags)."\n");
})->group('api-contract');

it('has a request for every operation of the document, and none that is not in it', function () {
    $expected = [];
    foreach (openApiOperations() as [$method, $path]) {
        $expected[] = $method.' {{base_url}}'.preg_replace('/\{([^}]+)\}/', ':$1', $path);
    }
    $have = array_keys(($this->items)());
    sort($expected);
    sort($have);

    expect($have)->toBe($expected);
})->group('api-contract');

it('signs in the way the app does: a bearer token on what needs one, none on the open doors, and the new tokens saved after signing in', function () {
    $items = ($this->items)();
    $login = $items['POST {{base_url}}/auth/login'];

    expect($items['GET {{base_url}}/me']['request'])->not->toHaveKey('auth')
        ->and($items['GET {{base_url}}/products']['request']['auth'])->toBe(['type' => 'noauth'])
        ->and(json_encode($login['event']))->toContain('refresh_token')->toContain('ONCE')
        ->and(json_encode($items['POST {{base_url}}/auth/refresh']['event']))->toContain('refresh_token');
})->group('api-contract');

it('fills in what placing an order needs, and leaves a guest\'s tokens off until they are wanted', function () {
    $items = ($this->items)();
    $headers = fn (string $key) => collect($items[$key]['request']['header'])->keyBy('key');

    expect($headers('POST {{base_url}}/checkout/orders')['Idempotency-Key']['value'])->toBe('{{$guid}}')
        ->and($headers('POST {{base_url}}/checkout/orders')['X-Cart-Token']['disabled'])->toBeTrue()
        ->and($headers('POST {{base_url}}/checkout/orders')['Authorization']['disabled'])->toBeTrue()
        ->and($headers('GET {{base_url}}/orders/:id')['X-Order-Token']['disabled'])->toBeTrue()
        ->and($headers('GET {{base_url}}/products')->has('Authorization'))->toBeFalse()
        ->and($headers('GET {{base_url}}/products')['Accept-Language']['value'])->toBe('{{locale}}');
})->group('api-contract');

it('gives a body that satisfies the request\'s own rules where it can be guessed, and path variables for ids', function () {
    $items = ($this->items)();
    $body = json_decode($items['POST {{base_url}}/auth/login']['request']['body']['raw'], true);

    expect($body)->toHaveKeys(['identifier', 'password'])->and($items['PUT {{base_url}}/me/addresses/:id']['request']['url']['variable'][0]['key'])->toBe('id')
        ->and($items['POST {{base_url}}/cart/items']['request']['url']['raw'])->toBe('{{base_url}}/cart/items');
})->group('api-contract');

it('holds no real secret, address or token', function () {
    $text = (string) file_get_contents(base_path('docs/api/velmora.postman_collection.json')).(string) file_get_contents(base_path('docs/api/velmora.postman_environment.json'));
    $env = json_decode((string) file_get_contents(base_path('docs/api/velmora.postman_environment.json')), true);

    expect(collect($env['values'])->whereIn('key', ['access_token', 'refresh_token', 'cart_token', 'order_token'])->pluck('value')->unique()->all())->toBe([''])
        ->and($text)->not->toMatch('/[A-Za-z0-9]{40,}/')->not->toContain('@gmail')->not->toContain(config('app.key') ?: 'APP_KEY_UNSET');
})->group('api-contract');
