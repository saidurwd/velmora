<?php

declare(strict_types=1);

use App\Modules\Platform\Enums\ErrorCode;
use Illuminate\Support\Facades\Artisan;

/*
 * Phase 11, slice 8: the gates a change to the API passes before it reaches an app already in people's hands (run on their own in CI as the `api-contract` group).
 * The document is also checked to be complete enough that a mobile developer can build the shopping journey from it alone.
 */

it('changes nothing an app already out there could trip on', function () {
    expect(Artisan::call('api:compat'))->toBe(0, Artisan::output());
})->group('api-contract');

it('has a frozen baseline that is a real, readable document', function () {
    $baseline = json_decode((string) file_get_contents(base_path('docs/api/baseline/openapi.v1.json')), true, 512, JSON_THROW_ON_ERROR);

    expect($baseline['openapi'])->toStartWith('3.')->and($baseline['paths'])->not->toBeEmpty()->and(array_keys($baseline['paths']))->toContain('/checkout/orders', '/auth/refresh', '/products');
})->group('api-contract');

it('describes every operation: a name, a group, a unique id and what it answers', function () {
    $ids = [];
    $problems = [];
    foreach (openApiOperations() as [$method, $path, $op]) {
        $at = "{$method} {$path}";
        trim((string) ($op['summary'] ?? '')) === '' && $problems[] = "{$at} has no summary";
        empty($op['tags']) && $problems[] = "{$at} is in no group";
        $id = (string) ($op['operationId'] ?? '');
        ($id === '' || isset($ids[$id])) && $problems[] = "{$at} has a missing or repeated operation id";
        $ids[$id] = true;
        $success = array_filter(array_keys($op['responses'] ?? []), fn ($s) => str_starts_with((string) $s, '2'));
        $success === [] && $problems[] = "{$at} says no success answer";
        foreach ($success as $status) {
            $body = $op['responses'][$status];
            (string) $status !== '204' && empty($body['content']['application/json']['schema']) && ! in_array($at, ['GET /orders/{id}/invoice', 'GET /me/privacy/exports/{id}'], true) && $problems[] = "{$at} {$status} does not say what it returns";
        }
    }

    expect($problems)->toBe([]);
})->group('api-contract');

it('says which answers need a sign-in: every operation behind one lists the 401', function () {
    $document = openApiDocument();
    $missing = [];
    foreach (openApiOperations() as [$method, $path, $op]) {
        $public = isset($op['security']) && array_filter($op['security']) === [];
        $optional = in_array("{$method} {$path}", ['GET /orders/{id}', 'GET /orders/{id}/invoice', 'POST /orders/{id}/payments', 'GET /orders/{id}/payment', 'POST /orders/{id}/payment/verify', 'GET /orders/{id}/tracking', 'POST /services/{sku}/hold'], true);
        if (! $public && ! $optional && ! isset($op['responses']['401'])) {
            $missing[] = "{$method} {$path}";
        }
    }

    expect($document['security'])->toBe([['http' => []]])->and($missing)->toBe([]);
})->group('api-contract');

it('offers the whole shopping journey: browse, cart, quote, place, pay, follow', function () {
    $have = array_map(fn (array $o): string => $o[0].' '.$o[1], openApiOperations());

    expect($have)->toContain('GET /config', 'GET /products', 'GET /products/{slug}', 'GET /search', 'POST /auth/register', 'POST /auth/login', 'POST /auth/refresh', 'POST /cart/items', 'GET /cart', 'POST /cart/coupons',
        'GET /geo/divisions', 'POST /checkout/quote', 'POST /checkout/orders', 'POST /orders/{id}/payments', 'POST /orders/{id}/payment/verify', 'GET /orders/{id}', 'GET /orders/{id}/tracking', 'POST /devices');
})->group('api-contract');

it('has an integration guide that lists every error code with its real status, so a new code cannot ship unexplained', function () {
    $guide = (string) file_get_contents(base_path('docs/api/MOBILE_INTEGRATION_GUIDE.md'));
    preg_match_all('/^\| `([a-z_.]+)` \| (\d{3}) \|/m', $guide, $rows, PREG_SET_ORDER);
    $listed = [];
    foreach ($rows as [, $code, $status]) {
        $listed[$code] = (int) $status;
    }
    $actual = [];
    foreach (ErrorCode::cases() as $case) {
        $actual[$case->value] = $case->status();
    }

    expect($listed)->toBe($actual);
})->group('api-contract');

it('keeps the documents the guide points at', function () {
    $guide = (string) file_get_contents(base_path('docs/api/MOBILE_INTEGRATION_GUIDE.md'));
    preg_match_all('/\]\(([A-Za-z_.\/]+)\)/', $guide, $links);

    expect($links[1])->not->toBeEmpty();
    foreach (array_unique($links[1]) as $file) {
        expect(base_path('docs/api/'.$file))->toBeFile();
    }
    foreach (['Idempotency-Key', 'X-Cart-Token', 'X-Order-Token', 'X-App-Version', 'Retry-After', 'refresh_reused', 'If-None-Match', 'Deprecation', 'Sunset'] as $word) {
        expect($guide.(string) file_get_contents(base_path('docs/api/VERSIONING_AND_DEPRECATION.md')))->toContain($word);
    }
})->group('api-contract');
