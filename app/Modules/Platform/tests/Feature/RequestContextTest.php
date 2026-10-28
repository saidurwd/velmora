<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

it('adds a correlation id to every response', function () {
    Route::get('/_test/ping', fn () => 'pong');

    $response = $this->get('/_test/ping');

    expect($response->headers->get('X-Request-Id'))->toMatch('/^[a-z0-9]{26}$/');
});

it('echoes a safe inbound request id and replaces an unsafe one', function () {
    Route::get('/_test/ping', fn () => 'pong');

    $this->get('/_test/ping', ['X-Request-Id' => 'abc-12345678'])->assertHeader('X-Request-Id', 'abc-12345678');

    $unsafe = $this->get('/_test/ping', ['X-Request-Id' => "bad id\r\n<script>"]);
    expect($unsafe->headers->get('X-Request-Id'))->not->toContain('script');
});
