<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Actions\IssueApiSession;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Notifications\Actions\DeliverNotification;
use App\Modules\Notifications\Actions\RegisterDevice;
use App\Modules\Notifications\Actions\SaveMessagePreferences;
use App\Modules\Notifications\Contracts\NotificationSender;
use App\Modules\Notifications\Contracts\TemplateRegistry;
use App\Modules\Notifications\DTOs\Recipient;
use App\Modules\Notifications\DTOs\TemplateDefinition;
use App\Modules\Notifications\Push\FakePushGateway;
use App\Modules\Notifications\Services\TemplateCatalog;
use App\Modules\Notifications\Sms\FakeSmsGateway;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderMessages;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use Carbon\CarbonImmutable;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/* Phase 11, slice 7: push messages to the app, through the same recording, retry and idempotency as texts and email. */

beforeEach(function () {
    $this->seed(LanguageSeeder::class);
    config(['services.push.driver' => 'fake', 'services.sms.driver' => 'fake']);
    $this->push = app(FakePushGateway::class);
    $this->push->fail = false;
    $this->push->gone = [];
    $this->push->down = [];
    $this->travelTo(CarbonImmutable::parse('2026-12-01 06:00:00', 'UTC'));   // 12:00 in Dhaka
    app(TemplateRegistry::class)->register(new TemplateDefinition('test.promo.push', 'push', 'marketing', ['shop'], ['en' => [null, 'Big sale at {{ shop }}']], ['shop' => 'Velmora']));
    $this->amina = User::factory()->create(['email' => 'amina@example.com']);
    $this->babul = User::factory()->create(['email' => 'babul@example.com']);
    $this->device = fn (User $u, string $token, string $locale = 'en') => app(RegisterDevice::class)->handle($u->id, strtolower((string) Str::ulid()), $token, 'android', '1.0.0', $locale);
    $this->send = fn (string $code, ?User $to, string $key = 'k1', array $vars = []) => app(NotificationSender::class)->send($code, new Recipient(locale: 'en', userId: $to?->id), $vars + ['shop' => 'Velmora', 'number' => 'VM-2612-000001'], $key, 'order:01hxyz');
    $this->log = fn (string $publicId) => DB::table('notification_logs')->where('public_id', $publicId)->first();
});

it('pushes to every signed-in phone of the person, each in its own language, with the shop as title and the order as the tap target', function () {
    ($this->device)($this->amina, 'token-en', 'en');
    ($this->device)($this->amina, 'token-bn', 'bn');
    ($this->device)($this->babul, 'token-other', 'en');

    $row = ($this->log)(($this->send)('order.shipped.push', $this->amina));
    $sent = collect($this->push->sent())->keyBy('token');

    expect($sent->keys()->all())->toBe(['token-en', 'token-bn'])
        ->and($sent['token-en']['message']->body)->toBe('Your order VM-2612-000001 is on its way.')
        ->and($sent['token-bn']['message']->body)->toBe('আপনার অর্ডার VM-2612-000001 পথে আছে।')
        ->and($sent['token-en']['message']->title)->toBe('Velmora')
        ->and($sent['token-en']['message']->data)->toBe(['target' => 'order:01hxyz', 'template' => 'order.shipped.push'])
        ->and($row->status)->toBe('sent')->and($row->recipient_masked)->toBe('app')->and($row->payload)->toBeNull();
});

it('falls back to the message\'s own language when a phone has none of its own, and to English after that', function () {
    ($this->device)($this->amina, 'token-x', 'en');
    DB::table('devices')->update(['locale' => 'fr']);   // a language we have no words for

    ($this->send)('order.delivered.push', $this->amina);

    expect($this->push->sent()[0]['message']->body)->toBe('Your order VM-2612-000001 was delivered.');
});

it('records a skipped message, and sends nothing, when the person has no signed-in phone or the message has no person', function () {
    $none = ($this->log)(($this->send)('order.shipped.push', $this->amina, 'a'));
    $guest = ($this->log)(($this->send)('order.shipped.push', null, 'b'));

    expect($none->status)->toBe('skipped')->and($none->skip_reason)->toBe('no_device')->and((int) $none->attempts)->toBe(0)->and($none->payload)->toBeNull()->and($guest->skip_reason)->toBe('no_address')->and($this->push->sent())->toBe([]);
});

it('announces one event once, however often it is announced', function () {
    ($this->device)($this->amina, 'token-1');

    $a = ($this->send)('order.shipped.push', $this->amina, 'evt-9');
    $b = ($this->send)('order.shipped.push', $this->amina, 'evt-9');

    expect($a)->toBe($b)->and($this->push->sent())->toHaveCount(1)->and(DB::table('notification_logs')->count())->toBe(1);
});

it('does not send a message again once it has been sent (a second worker, a repeated run)', function () {
    ($this->device)($this->amina, 'token-1');
    $id = ($this->send)('order.shipped.push', $this->amina);
    $rowId = (int) DB::table('notification_logs')->where('public_id', $id)->value('id');

    expect(app(DeliverNotification::class)->handle($rowId))->toBe('busy')->and($this->push->sent())->toHaveCount(1);
});

describe('phones that are gone or failing', function () {
    it('forgets a phone the provider says is gone and still reaches the others', function () {
        ($this->device)($this->amina, 'token-live');
        ($this->device)($this->amina, 'token-dead');
        $this->push->gone = ['token-dead'];

        $row = ($this->log)(($this->send)('order.shipped.push', $this->amina));

        expect($row->status)->toBe('sent')->and(array_column($this->push->sent(), 'token'))->toBe(['token-live'])
            ->and(DB::table('devices')->where('user_id', $this->amina->id)->count())->toBe(1)
            ->and(DB::table('devices')->where('push_token_hash', hash('sha256', 'token-dead'))->exists())->toBeFalse();
    });

    it('skips, rather than fails, when every phone is gone, and does not retry', function () {
        ($this->device)($this->amina, 'token-dead');
        $this->push->gone = ['token-dead'];

        $row = ($this->log)(($this->send)('order.shipped.push', $this->amina));

        expect($row->status)->toBe('skipped')->and($row->skip_reason)->toBe('no_device')->and($row->payload)->toBeNull()->and(DB::table('devices')->count())->toBe(0);
    });

    it('tries again later when the provider is down, and succeeds once it is back', function () {
        ($this->device)($this->amina, 'token-1');
        $this->push->fail = true;

        $row = ($this->log)(($this->send)('order.shipped.push', $this->amina));
        expect($row->status)->toBe('failed')->and($row->last_error)->toBe('fake:unavailable')->and($row->payload)->not->toBeNull()->and($this->push->sent())->toBe([]);

        $this->push->fail = false;
        $this->travelTo(now()->addDay());
        expect(app(DeliverNotification::class)->handle((int) $row->id))->toBe('sent')->and($this->push->sent())->toHaveCount(1)
            ->and(DB::table('notification_logs')->where('id', $row->id)->value('payload'))->toBeNull();
    });

    it('gives up after the last wait, and staff can see it', function () {
        ($this->device)($this->amina, 'token-1');
        $this->push->fail = true;
        $id = ($this->send)('order.shipped.push', $this->amina);
        $rowId = (int) ($this->log)($id)->id;

        for ($i = 0; $i < count((array) config('notifications.backoff_minutes')) + 1; $i++) {
            $this->travelTo(now()->addDays(2));
            app(DeliverNotification::class)->handle($rowId);
        }

        expect(($this->log)($id)->status)->toBe('dead');
    });

    it('does not show the message twice on a phone that already has it when another phone fails', function () {
        ($this->device)($this->amina, 'token-live');
        ($this->device)($this->amina, 'token-down');
        $this->push->down = ['token-down'];

        $row = ($this->log)(($this->send)('order.shipped.push', $this->amina));

        expect($row->status)->toBe('sent')->and($this->push->sent())->toHaveCount(1);
    });
});

describe('who may be pushed offers', function () {
    it('does not push marketing without a yes, and does with one', function () {
        ($this->device)($this->amina, 'token-1');

        $no = ($this->log)(($this->send)('test.promo.push', $this->amina, 'm1'));
        expect($no->status)->toBe('skipped')->and($no->skip_reason)->toBe('no_consent')->and($this->push->sent())->toBe([]);

        app(SaveMessagePreferences::class)->handle($this->amina->id, ['push' => true], null, null);
        $yes = ($this->log)(($this->send)('test.promo.push', $this->amina, 'm2'));
        expect($yes->status)->toBe('sent')->and($this->push->sent())->toHaveCount(1);
    });

    it('waits out quiet hours for marketing but not for a message about the person\'s own order', function () {
        ($this->device)($this->amina, 'token-1');
        app(SaveMessagePreferences::class)->handle($this->amina->id, ['push' => true], '10:00', '14:00');   // 12:00 in Dhaka is inside

        $promo = ($this->log)(($this->send)('test.promo.push', $this->amina, 'm1'));
        $order = ($this->log)(($this->send)('order.shipped.push', $this->amina, 'o1'));

        expect($promo->status)->toBe('queued')->and($order->status)->toBe('sent')->and($this->push->sent())->toHaveCount(1);
    });

    it('records each change of the push answer in the history, once', function () {
        $save = app(SaveMessagePreferences::class);
        $save->handle($this->amina->id, ['push' => true], null, null);
        $save->handle($this->amina->id, ['push' => true], null, null);
        $save->handle($this->amina->id, ['push' => false], null, null);

        expect(DB::table('notification_consent_events')->where('user_id', $this->amina->id)->where('channel', 'push')->orderBy('id')->pluck('granted')->map(fn ($g) => (bool) $g)->all())->toBe([true, false]);
    });
});

describe('what an order pushes', function () {
    beforeEach(function () {
        $this->order = fn (?int $userId) => (new Order)->forceFill(['public_id' => '01hxyzorder', 'order_number' => 'VM-2612-000009', 'locale' => 'en', 'contact_phone' => '01712345678', 'contact_email' => 'amina@example.com', 'user_id' => $userId]);
    });

    it('pushes the refund without the amount, and the text message keeps it', function () {
        ($this->device)($this->amina, 'token-1');

        app(OrderMessages::class)->send(($this->order)($this->amina->id), 'order.refunded.sms', ['amount' => '৳ 1,234.00'], 'refund-1');

        $push = $this->push->sent()[0]['message'];
        $everything = json_encode([$push->title, $push->body, $push->data], JSON_UNESCAPED_UNICODE);
        expect($push->body)->toBe('A refund for order VM-2612-000009 is on its way.')->and($everything)->not->toContain('1,234')->not->toContain('01712345678')->not->toContain('amina@example.com')
            ->and($push->data)->toBe(['target' => 'order:01hxyzorder', 'template' => 'order.refunded.push'])
            ->and(app(FakeSmsGateway::class)->sent()[0]['message'])->toContain('1,234.00');
    });

    it('pushes for each order event, once per event', function (string $sms, string $push) {
        ($this->device)($this->amina, 'token-1');
        $messages = app(OrderMessages::class);

        $messages->send(($this->order)($this->amina->id), $sms, ['total' => '৳ 10.00'], 'evt-1');
        $messages->send(($this->order)($this->amina->id), $sms, ['total' => '৳ 10.00'], 'evt-1');

        expect($this->push->sent())->toHaveCount(1)->and($this->push->sent()[0]['message']->data['template'])->toBe($push)->and($this->push->sent()[0]['message']->body)->toContain('VM-2612-000009');
    })->with([
        ['order.placed.sms', 'order.placed.push'], ['order.placed_cod.sms', 'order.placed.push'], ['order.shipped.sms', 'order.shipped.push'], ['order.delivered.sms', 'order.delivered.push'],
        ['order.delivery_failed.sms', 'order.delivery_failed.push'], ['order.cancelled_stale.sms', 'order.cancelled.push'],
    ]);

    it('pushes nothing for a guest order, and records no skipped push for it either', function () {
        app(OrderMessages::class)->send(($this->order)(null), 'order.shipped.sms', [], 'evt-1');

        expect($this->push->sent())->toBe([])->and(DB::table('notification_logs')->where('channel', 'push')->count())->toBe(0);
    });

    it('never pushes to another customer\'s phone', function () {
        ($this->device)($this->babul, 'token-babul');

        app(OrderMessages::class)->send(($this->order)($this->amina->id), 'order.shipped.sms', [], 'evt-1');

        expect($this->push->sent())->toBe([]);
    });

    it('has English and Bangla words for every push message, with no amount or total in any', function () {
        $catalog = app(TemplateCatalog::class);
        $pushes = array_filter($catalog->all(), fn ($d) => $d->channel === 'push' && str_starts_with($d->code, 'order.'));

        expect($pushes)->toHaveCount(7);
        foreach ($pushes as $definition) {
            expect(array_keys($definition->defaults))->toBe(['en', 'bn'])->and($definition->variables)->not->toContain('amount')->not->toContain('total');
            foreach ($definition->defaults as [$subject, $body]) {
                expect($body)->not->toBe('')->and(mb_strlen($body))->toBeLessThanOrEqual((int) config('notifications.max_length.push'))->and($subject)->toBeNull();
            }
        }
    });
});

describe('the preferences in the app', function () {
    beforeEach(function () {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->h = function (User $u) {
            auth()->forgetGuards();

            return ['Authorization' => 'Bearer '.app(IssueApiSession::class)->start($u, 'Pixel', 'android', 'password')->accessToken];
        };
    });

    it('lets the customer say yes to push offers, leaves the answer alone when push is left out, and documents the shape', function () {
        $u = User::factory()->verified()->create();

        $res = $this->putJson('/api/v1/me/preferences', ['email' => false, 'sms' => false, 'push' => true], ($this->h)($u));
        $res->assertOk()->assertJsonPath('data.marketing.push', true);
        assertMatchesOpenApi($res, 'PUT', '/api/v1/me/preferences');

        $this->putJson('/api/v1/me/preferences', ['email' => true, 'sms' => false], ($this->h)($u))->assertOk()->assertJsonPath('data.marketing.push', true)->assertJsonPath('data.marketing.email', true);
        $this->putJson('/api/v1/me/preferences', ['email' => true, 'sms' => false, 'push' => false], ($this->h)($u))->assertOk()->assertJsonPath('data.marketing.push', false);
        $this->getJson('/api/v1/me/preferences', ($this->h)($u))->assertOk()->assertJsonPath('data.marketing.push', false);
    });

    it('refuses a push answer that is not yes or no', function () {
        $u = User::factory()->verified()->create();

        $this->putJson('/api/v1/me/preferences', ['email' => false, 'sms' => false, 'push' => 'maybe'], ($this->h)($u))->assertUnprocessable();
    });

    it('keeps one customer\'s push answer from another\'s', function () {
        $a = User::factory()->verified()->create();
        $b = User::factory()->verified()->create();

        $this->putJson('/api/v1/me/preferences', ['email' => false, 'sms' => false, 'push' => true], ($this->h)($a))->assertOk();

        $this->getJson('/api/v1/me/preferences', ($this->h)($b))->assertOk()->assertJsonPath('data.marketing.push', false);
    });
});
