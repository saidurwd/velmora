<?php

declare(strict_types=1);

use App\Modules\Notifications\Contracts\NotificationSender;
use App\Modules\Notifications\DTOs\Recipient;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderMessages;
use Illuminate\Support\Facades\Log;

/** A sender that records what it is asked and can be told to fail. */
function recordingSender(bool $fail = false): object
{
    return new class($fail) implements NotificationSender
    {
        /** @var list<array{code: string, to: Recipient, variables: array<string, mixed>, key: string, related: string|null, locale: string}> */
        public array $asked = [];

        public function __construct(private readonly bool $fail) {}

        public function send(string $templateCode, Recipient $to, array $variables, string $idempotencyKey, ?string $related = null): string
        {
            if ($this->fail) {
                throw new RuntimeException('could not record for 01712345678');
            }
            $this->asked[] = ['code' => $templateCode, 'to' => $to, 'variables' => $variables, 'key' => $idempotencyKey, 'related' => $related, 'locale' => app()->getLocale()];

            return 'id';
        }
    };
}

beforeEach(function () {
    $this->order = (new Order)->forceFill(['order_number' => 'VM-2611-000001', 'public_id' => '01m6order0000000000000000ab', 'contact_phone' => '+8801712345678', 'contact_email' => 'r@example.com', 'locale' => 'bn', 'user_id' => 7]);
});

it('asks the platform with the order\'s phone, email, language and owner, the shop and the order number, and the order as the related object', function () {
    $sender = recordingSender();
    $this->app->instance(NotificationSender::class, $sender);
    app()->setLocale('en');

    app(OrderMessages::class)->send($this->order, 'order.shipped.sms', ['extra' => 'x'], 'key-1');

    $asked = $sender->asked[0];
    expect($asked['code'])->toBe('order.shipped.sms')->and($asked['to']->phone)->toBe('+8801712345678')->and($asked['to']->email)->toBe('r@example.com')->and($asked['to']->locale)->toBe('bn')->and($asked['to']->userId)->toBe(7)
        ->and($asked['variables'])->toBe(['extra' => 'x', 'shop' => config('app.name'), 'number' => 'VM-2611-000001'])->and($asked['key'])->toBe('key-1')->and($asked['related'])->toBe('order:01m6order0000000000000000ab')
        ->and($asked['locale'])->toBe('bn')->and(app()->getLocale())->toBe('en');   // values are made in the order's language, and the page's own language is put back
});

it('throws a problem recording a message when asked to, so the caller\'s own retry (the outbox) tries again', function () {
    $this->app->instance(NotificationSender::class, recordingSender(fail: true));

    expect(fn () => app(OrderMessages::class)->send($this->order, 'order.shipped.sms', [], 'k'))->toThrow(RuntimeException::class);
});

it('only writes a warning, without the customer\'s details, when the message must never undo what it announces', function () {
    Log::spy();
    $this->app->instance(NotificationSender::class, recordingSender(fail: true));

    app(OrderMessages::class)->tryToSend($this->order, 'order.refunded.sms', ['amount' => '৳ 5'], 'k');

    Log::shouldHaveReceived('warning')->withArgs(fn ($m, $c = []) => $m === 'orders.message_not_recorded' && ! str_contains(json_encode($c, JSON_UNESCAPED_UNICODE), '0171') && $c['message'] === 'order.refunded.sms')->once();
});
