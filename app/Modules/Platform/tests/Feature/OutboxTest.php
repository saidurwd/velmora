<?php

declare(strict_types=1);

use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Outbox\DomainEvent;
use App\Modules\Platform\Outbox\IdempotentConsumer;
use App\Modules\Platform\Outbox\Outbox;
use App\Modules\Platform\Outbox\OutboxDispatcher;
use App\Modules\Platform\Outbox\OutboxRegistry;
use App\Modules\Platform\Support\FrozenClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

final class PaymentSucceededTestEvent extends DomainEvent
{
    public function __construct(public readonly string $paymentId) {}

    public static function eventName(): string
    {
        return 'test.payment_succeeded';
    }

    public function toPayload(): array
    {
        return ['payment_id' => $this->paymentId];
    }

    public static function fromPayload(array $payload): static
    {
        return new self((string) $payload['payment_id']);
    }
}

beforeEach(function () {
    Queue::fake(); // no inline dispatch; tests call the dispatcher explicitly

    $this->clock = new FrozenClock('2026-05-01 10:00:00');
    app()->instance(Clock::class, $this->clock);
    app(OutboxRegistry::class)->register(PaymentSucceededTestEvent::class);

    $this->effects = [];
    Event::listen(PaymentSucceededTestEvent::class, function (PaymentSucceededTestEvent $event) {
        app(IdempotentConsumer::class)->once('test.orders_listener', $event, function () use ($event) {
            $this->effects[] = $event->paymentId;
        });
    });
});

it('does not publish anything when the surrounding transaction rolls back', function () {
    try {
        DB::transaction(function () {
            app(Outbox::class)->record(new PaymentSucceededTestEvent('pay_1'));

            throw new RuntimeException('order creation failed');
        });
    } catch (RuntimeException) {
    }

    expect(DB::table('outbox_messages')->count())->toBe(0);
});

it('delivers a committed event even if the process died before dispatch (crash simulation)', function () {
    DB::transaction(fn () => app(Outbox::class)->record(new PaymentSucceededTestEvent('pay_1')));
    // process "crashes" here: nothing dispatched. Later, the scheduler/worker picks it up:

    expect(app(OutboxDispatcher::class)->dispatchDue())->toBe(1)
        ->and($this->effects)->toBe(['pay_1'])
        ->and(DB::table('outbox_messages')->value('status'))->toBe('done');
});

it('has an exactly-once effect even when a message is delivered twice', function () {
    DB::transaction(fn () => app(Outbox::class)->record(new PaymentSucceededTestEvent('pay_1')));

    app(OutboxDispatcher::class)->dispatchDue();

    // crash AFTER the listener ran but BEFORE the dispatcher committed "done": the message is pending again
    DB::table('outbox_messages')->update(['status' => 'pending', 'processed_at' => null]);
    app(OutboxDispatcher::class)->dispatchDue();

    expect($this->effects)->toBe(['pay_1'])
        ->and(DB::table('consumed_events')->count())->toBe(1);
});

it('does not deliver messages twice once done', function () {
    DB::transaction(fn () => app(Outbox::class)->record(new PaymentSucceededTestEvent('pay_1')));

    expect(app(OutboxDispatcher::class)->dispatchDue())->toBe(1)
        ->and(app(OutboxDispatcher::class)->dispatchDue())->toBe(0);
});

it('backs off and eventually fails a poison message instead of blocking the queue', function () {
    Event::listen(PaymentSucceededTestEvent::class, fn () => throw new RuntimeException('listener exploded'));
    config(['velmora.outbox.max_attempts' => 3, 'velmora.outbox.base_backoff_seconds' => 10]);

    DB::transaction(fn () => app(Outbox::class)->record(new PaymentSucceededTestEvent('pay_1')));

    app(OutboxDispatcher::class)->dispatchDue();
    $row = DB::table('outbox_messages')->first();

    expect($row->status)->toBe('pending')->and($row->attempts)->toBe(1)->and($row->last_error)->toContain('listener exploded');

    // not due yet
    expect(app(OutboxDispatcher::class)->dispatchDue())->toBe(0);

    $this->clock->advanceSeconds(3600);
    app(OutboxDispatcher::class)->dispatchDue();
    $this->clock->advanceSeconds(36000);
    app(OutboxDispatcher::class)->dispatchDue();

    expect(DB::table('outbox_messages')->value('status'))->toBe('failed');
});

it('fails messages whose event type is unknown, without crashing the dispatcher', function () {
    DB::table('outbox_messages')->insert(['public_id' => strtolower(Str::ulid()->toBase32()), 'event_name' => 'nope.unknown', 'payload' => '{}', 'status' => 'pending', 'available_at' => '2026-05-01 09:00:00', 'created_at' => '2026-05-01 09:00:00']);

    expect(app(OutboxDispatcher::class)->dispatchDue())->toBe(0)
        ->and(DB::table('outbox_messages')->value('last_error'))->toContain('Unknown outbox event');
});

it('refuses to register two classes under one event name', function () {
    app(OutboxRegistry::class)->register(AnotherEventWithSameName::class);
})->throws(InvalidArgumentException::class);

final class AnotherEventWithSameName extends DomainEvent
{
    public static function eventName(): string
    {
        return 'test.payment_succeeded';
    }

    public function toPayload(): array
    {
        return [];
    }

    public static function fromPayload(array $payload): static
    {
        return new self;
    }
}
