<?php

declare(strict_types=1);

use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Support\FrozenClock;
use App\Modules\Platform\Support\SystemClock;

it('builds explicit actors', function () {
    expect(Actor::system()->type)->toBe(ActorType::System)
        ->and(Actor::guest()->type)->toBe(ActorType::Guest)
        ->and(Actor::provider('sslcommerz')->label)->toBe('sslcommerz')
        ->and((new Actor(ActorType::Staff, 7))->isStaff())->toBeTrue();
});

it('exposes UTC and Asia/Dhaka views of the same instant', function () {
    config(['app.display_timezone' => 'Asia/Dhaka']);
    $clock = new FrozenClock('2026-03-01 20:30:00');

    expect($clock->now()->toIso8601String())->toBe('2026-03-01T20:30:00+00:00')
        ->and($clock->nowInDisplayZone()->toIso8601String())->toBe('2026-03-02T02:30:00+06:00');
});

it('lets tests move time', function () {
    $clock = new FrozenClock('2026-01-01 00:00:00');
    $clock->advanceSeconds(90);

    expect($clock->now()->format('H:i:s'))->toBe('00:01:30');
});

it('system clock is UTC', function () {
    expect((new SystemClock)->now()->timezoneName)->toBe('UTC');
});
