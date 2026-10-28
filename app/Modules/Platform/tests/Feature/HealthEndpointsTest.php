<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;

it('answers liveness without touching dependencies', function () {
    $this->getJson('/healthz')->assertOk()->assertExactJson(['status' => 'ok']);
});

it('reports readiness with dependency checks', function () {
    $this->getJson('/readyz')->assertOk()->assertJson(['status' => 'ready', 'checks' => ['database' => true, 'cache' => true]]);
});

it('flags a stale scheduler without failing readiness', function () {
    Cache::forget('scheduler:heartbeat');
    $this->getJson('/readyz')->assertOk()->assertJson(['scheduler' => 'stale']);

    Cache::put('scheduler:heartbeat', now()->toIso8601String(), 600);
    $this->getJson('/readyz')->assertOk()->assertJson(['scheduler' => 'ok']);
});
