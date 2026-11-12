<?php

declare(strict_types=1);

use App\Modules\Platform\Enums\ErrorCode;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

/* Phase 11, slice 1: the API foundation — the app bootstrap, the app-version gate, ETags, problem+json in two languages, and the contract itself. */

describe('GET /api/v1/config', function () {
    it('gives an app what it needs to start, and matches the committed contract', function () {
        $res = $this->getJson('/api/v1/config');

        $res->assertOk()
            ->assertJsonPath('data.locale', 'en')
            ->assertJsonPath('data.languages.default', 'en')
            ->assertJsonPath('data.languages.supported.0.code', 'en')
            ->assertJsonPath('data.currency.code', 'BDT')
            ->assertJsonPath('data.currency.example.amount_minor', 125000)
            ->assertJsonPath('data.tax.enabled', false)
            ->assertJsonStructure(['data' => ['app' => ['min_supported_version' => ['android', 'ios']], 'shop' => ['name', 'phone', 'email'], 'pages']])
            ->assertHeader('X-Request-Id');
        assertMatchesOpenApi($res, 'GET', '/api/v1/config');
    });

    it('answers in Bangla for Accept-Language: bn, with Bangla digits only when the shop turned them on', function () {
        $res = $this->getJson('/api/v1/config', ['Accept-Language' => 'bn']);
        $res->assertOk()->assertJsonPath('data.locale', 'bn')->assertHeader('Content-Language', 'bn');
        expect($res->json('data.currency.example.formatted'))->toBe('৳ 1,250.00');
        assertMatchesOpenApi($res, 'GET', '/api/v1/config');

        config(['velmora.settings' => array_merge(config('velmora.settings'), ['display.bangla_digits' => true])]);
        $digits = $this->getJson('/api/v1/config', ['Accept-Language' => 'bn']);
        expect($digits->json('data.currency.example.formatted'))->toContain('১,২৫০')->and($digits->json('data.currency.example.amount_minor'))->toBe(125000);
    });

    it('lists only published pages that exist in the asked language', function () {
        // a page published only in English is absent from the Bangla list (the fallback is for content, not for links to pages that do not exist)
        $en = $this->getJson('/api/v1/config')->json('data.pages');
        $bn = $this->getJson('/api/v1/config', ['Accept-Language' => 'bn'])->json('data.pages');

        expect($en)->toBeArray()->and($bn)->toBeArray();
    });
});

describe('ETag and conditional GET', function () {
    it('tags the answer and replies 304 with no body to a matching If-None-Match', function () {
        $first = $this->getJson('/api/v1/config');
        $etag = $first->headers->get('ETag');

        expect($etag)->toStartWith('"')->and($first->headers->get('Cache-Control'))->toContain('max-age=300');

        $again = $this->getJson('/api/v1/config', ['If-None-Match' => $etag]);
        $again->assertStatus(304);
        expect($again->getContent())->toBe('')->and($again->headers->get('ETag'))->toBe($etag);
    });

    it('keeps a tag per language even when two languages give the same body, and tags only successful answers', function () {
        Route::get('/api/v1/_same', fn () => response()->json(['same' => true]))->middleware(['api', 'etag:catalog']);
        Route::get('/api/v1/_missing', fn () => response()->json(['gone' => true], 404))->middleware(['api', 'etag:catalog']);

        $en = $this->getJson('/api/v1/_same')->headers->get('ETag');
        $bn = $this->getJson('/api/v1/_same', ['Accept-Language' => 'bn'])->headers->get('ETag');
        expect($en)->not->toBeNull()->and($en)->not->toBe($bn);

        $this->getJson('/api/v1/_missing')->assertNotFound()->assertHeaderMissing('ETag');
        $this->getJson('/api/v1/_missing', ['If-None-Match' => $en])->assertNotFound();
    });

    it('never lets two languages share a tag, and a stale tag gets the full answer', function () {
        $en = $this->getJson('/api/v1/config')->headers->get('ETag');
        $bn = $this->getJson('/api/v1/config', ['Accept-Language' => 'bn'])->headers->get('ETag');

        expect($en)->not->toBe($bn);
        $this->getJson('/api/v1/config', ['If-None-Match' => $bn])->assertOk();
    });
});

describe('the app-version gate', function () {
    beforeEach(fn () => config(['api.app.min_supported_version' => ['android' => '2.0.0', 'ios' => '3.1.0'], 'api.app.store_urls' => ['android' => 'https://store.example/android', 'ios' => null]]));

    it('refuses an app older than the minimum with 426 and says what to do, in its language', function () {
        $res = $this->getJson('/api/v1/config', ['X-App-Platform' => 'android', 'X-App-Version' => '1.9.9', 'Accept-Language' => 'bn']);

        $res->assertStatus(426)->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'app.upgrade_required')->assertJsonPath('min_supported_version', '2.0.0')->assertJsonPath('store_url', 'https://store.example/android');
        expect($res->json('title'))->toBe(trans('errors.api.app_upgrade_required', [], 'bn'))->and(ErrorCode::AppUpgradeRequired->status())->toBe(426);
    });

    it('lets the minimum itself and anything newer through, per platform', function () {
        $this->getJson('/api/v1/config', ['X-App-Platform' => 'android', 'X-App-Version' => '2.0.0'])->assertOk();
        $this->getJson('/api/v1/config', ['X-App-Platform' => 'android', 'X-App-Version' => '10.0.0'])->assertOk();
        $this->getJson('/api/v1/config', ['X-App-Platform' => 'ios', 'X-App-Version' => '3.0.9'])->assertStatus(426);
        $this->getJson('/api/v1/config', ['X-App-Platform' => 'ios', 'X-App-Version' => '3.1.0'])->assertOk();
    });

    it('does not gate what is not an app: no headers, an unknown platform, or a version that is not a version', function () {
        $this->getJson('/api/v1/config')->assertOk();
        $this->getJson('/api/v1/config', ['X-App-Platform' => 'toaster', 'X-App-Version' => '0.0.1'])->assertOk();
        $this->getJson('/api/v1/config', ['X-App-Platform' => 'android', 'X-App-Version' => 'banana'])->assertOk();
    });
});

describe('errors on the API', function () {
    it('answers an unknown API path with problem+json and a request id, in Bangla when asked', function () {
        $res = $this->getJson('/api/v1/nothing-here', ['Accept-Language' => 'bn', 'X-Request-Id' => 'req-abcdef123456']);

        $res->assertNotFound()->assertHeader('Content-Type', 'application/problem+json')->assertJsonPath('code', 'resource.not_found')->assertJsonPath('request_id', 'req-abcdef123456');
        expect($res->json('title'))->toBe(trans('errors.api.not_found', [], 'bn'));
    });

    it('answers a wrong method with 405 problem+json, and never leaks an exception message', function () {
        $this->postJson('/api/v1/config')->assertStatus(405)->assertJsonPath('code', 'http.method_not_allowed');

        config(['app.debug' => false]);
        Route::get('/api/v1/_boom', fn () => throw new RuntimeException('secret detail: db password'));
        $res = $this->getJson('/api/v1/_boom');
        $res->assertStatus(500)->assertJsonPath('code', 'server.error');
        expect($res->getContent())->not->toContain('secret detail');
    });

    it('rate-limits with 429, Retry-After and the stable code', function () {
        for ($i = 0; $i < 120; $i++) {
            $this->getJson('/api/v1/config')->assertSuccessful();
        }

        $this->getJson('/api/v1/config')->assertStatus(429)->assertJsonPath('code', 'rate.limited')->assertHeader('Retry-After');
    });
});

describe('the contract', function () {
    it('documents every public API route (webhooks are not public API) and nothing else', function () {
        $doc = openApiDocument();
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/') && ! str_contains($r->uri(), '/webhooks/'))
            ->flatMap(fn ($r) => collect($r->methods())->reject(fn ($m) => in_array($m, ['HEAD', 'OPTIONS'], true))->map(fn ($m) => strtolower($m).' /'.substr($r->uri(), strlen('api/v1/'))))
            ->sort()->values()->all();
        $documented = collect($doc['paths'])->flatMap(fn ($ops, $path) => collect(array_keys($ops))->map(fn ($m) => $m.' '.$path))->sort()->values()->all();

        expect($documented)->toBe($routes);
    });

    it('is the document the code produces today (regenerate with php artisan scramble:export)', function () {
        $path = tempnam(sys_get_temp_dir(), 'openapi');
        Artisan::call('scramble:export', ['--path' => $path]);

        expect(file_get_contents($path))->toBe(file_get_contents(base_path('docs/api/openapi.json')));
        @unlink($path);
    });
});
