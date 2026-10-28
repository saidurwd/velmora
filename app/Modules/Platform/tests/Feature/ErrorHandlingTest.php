<?php

declare(strict_types=1);

use App\Modules\Platform\Enums\ErrorCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(fn () => config(['app.debug' => false]));

it('returns problem+json with a stable code for unknown API routes', function () {
    $response = $this->getJson('/api/v1/nothing-here');

    $response->assertStatus(404)->assertJson(['status' => 404, 'code' => 'resource.not_found']);
    expect($response->headers->get('Content-Type'))->toContain('application/problem+json')
        ->and($response->json('request_id'))->not->toBeNull();
});

it('returns validation errors in problem+json with field errors', function () {
    Route::post('/api/v1/_test/validate', fn (Request $r) => $r->validate(['phone' => 'required']));

    $this->postJson('/api/v1/_test/validate', [])
        ->assertStatus(422)
        ->assertJson(['code' => 'validation.failed'])
        ->assertJsonValidationErrors('phone', 'errors');
});

it('hides internals on unexpected API errors', function () {
    Route::get('/api/v1/_test/boom', fn () => throw new RuntimeException('secret db password leaked'));

    $response = $this->getJson('/api/v1/_test/boom');

    $response->assertStatus(500)->assertJson(['code' => 'server.error']);
    expect($response->getContent())->not->toContain('secret db password');
});

it('localises problem titles by locale', function () {
    $this->getJson('/api/v1/nothing-here', ['Accept-Language' => 'bn'])->assertStatus(404)->assertJson(['title' => 'অনুরোধকৃত তথ্য পাওয়া যায়নি।']);
});

it('renders friendly web error pages in English and Bangla', function () {
    $this->get('/definitely-missing')->assertStatus(404)->assertSee('Page not found')->assertSee('lang="en"', false);

    $this->get('/definitely-missing', ['Accept-Language' => 'bn'])->assertStatus(404)->assertSee('পেজ পাওয়া যায়নি')->assertSee('lang="bn"', false);
});

it('has a stable status and unique code for every ErrorCode', function () {
    $codes = array_map(fn ($c) => $c->value, ErrorCode::cases());

    expect($codes)->toBe(array_values(array_unique($codes)));

    foreach (ErrorCode::cases() as $case) {
        expect($case->status())->toBeGreaterThanOrEqual(400);
        if ($case->titleKey()) {
            expect(__($case->titleKey()))->not->toBe($case->titleKey());
        }
    }
});
