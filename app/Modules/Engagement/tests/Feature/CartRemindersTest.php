<?php

declare(strict_types=1);

use App\Modules\Cart\Actions\AddToCart;
use App\Modules\Cart\Contracts\AbandonedCarts;
use App\Modules\Cart\Contracts\CartWriter;
use App\Modules\Cart\DTOs\AbandonedCart;
use App\Modules\Cart\DTOs\CartOwner;
use App\Modules\Customers\Contracts\CustomerProfiles;
use App\Modules\Engagement\Actions\SendCartReminders;
use App\Modules\Engagement\Privacy\EngagementPersonalData;
use App\Modules\IdentityAccess\Contracts\AccountLocales;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Notifications\Actions\SaveMessagePreferences;
use App\Modules\Notifications\Contracts\MarketingConsent;
use App\Modules\Notifications\Contracts\NotificationSender;
use App\Modules\Notifications\DTOs\Recipient;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Support\FeatureFlags;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    shopWorld($this);
    Mail::fake();
    config(['services.sms.driver' => 'fake', 'engagement.cart_recovery.enabled' => true]);
    /** A customer with a profile, an account language and (by default) a yes to marketing email. */
    $this->customer = function (string $name, ?string $email, ?string $phone = null, array $optIn = ['email' => true], string $locale = 'en'): User {
        $user = User::factory()->create(['name' => $name, 'email' => $email ?? 'none-'.uniqid().'@example.com', 'phone' => $phone, 'locale' => $locale]);
        app(CustomerProfiles::class)->ensureForUser($user->id, $name, $email, $phone);
        if ($optIn !== []) {
            app(SaveMessagePreferences::class)->handle($user->id, $optIn, null, null);
        }

        return $user;
    };
    $this->amina = ($this->customer)('Amina', 'amina@example.com', '+8801711111111');
    $this->fill = fn (User $user, ?array $v = null) => app(AddToCart::class)->handle(CartOwner::user($user->id), ($v ?? $this->tee)['variantId'], $v === null ? 1 : 2);   // the lamp is sold in twos
    $this->run = fn (): int => app(SendCartReminders::class)->handle();
    $this->logs = fn () => DB::table('notification_logs')->where('template_code', 'like', 'engagement.cart_reminder.%')->orderBy('id')->get();
    $this->claims = fn () => DB::table('cart_recovery_messages')->count();
    $this->idle = fn (int $hours = 3) => $this->travel($hours)->hours();   // time passes with nobody touching the cart
});

describe('who is reminded', function () {
    it('does nothing until the shop turns it on', function () {
        config(['engagement.cart_recovery.enabled' => false]);
        ($this->fill)($this->amina);
        ($this->idle)();

        expect(($this->run)())->toBe(0)->and(($this->logs)())->toHaveCount(0)->and(($this->claims)())->toBe(0);
    });

    it('reminds a customer who agreed to email about a cart left for a few hours', function () {
        ($this->fill)($this->amina);
        ($this->idle)();

        expect(($this->run)())->toBe(1);
        $log = ($this->logs)();
        expect($log)->toHaveCount(1)
            ->and($log[0]->template_code)->toBe('engagement.cart_reminder.email')
            ->and($log[0]->kind)->toBe('marketing')
            ->and($log[0]->status)->toBe('sent')   // the queue runs inline in tests
            ->and((int) $log[0]->user_id)->toBe($this->amina->id)
            ->and(($this->claims)())->toBe(1);
    });

    it('says nothing to a customer who never agreed, and leaves no trace of them', function () {
        $nobody = ($this->customer)('Nobody', 'nobody@example.com', '+8801722222222', optIn: []);
        $no = ($this->customer)('No', 'no@example.com', '+8801733333333', optIn: ['email' => false, 'sms' => false]);
        ($this->fill)($nobody);
        ($this->fill)($no);
        ($this->idle)();

        expect(($this->run)())->toBe(0)->and(DB::table('notification_logs')->count())->toBe(0)->and(($this->claims)())->toBe(0);
    });

    it('stops as soon as the customer says no', function () {
        ($this->fill)($this->amina);
        app(SaveMessagePreferences::class)->handle($this->amina->id, ['email' => false], null, null);
        ($this->idle)();

        expect(($this->run)())->toBe(0)->and(($this->logs)())->toHaveCount(0);
    });

    it('uses text when only text was agreed to, and only email when only email was', function () {
        $smsOnly = ($this->customer)('Sumi', 'sumi@example.com', '+8801744444444', ['sms' => true]);
        $noPhone = ($this->customer)('Rafi', 'rafi@example.com', null, ['sms' => true]);   // agreed to text but has no number
        ($this->fill)($smsOnly);
        ($this->fill)($noPhone);
        ($this->idle)();

        expect(($this->run)())->toBe(1);
        $log = ($this->logs)();
        expect($log)->toHaveCount(1)->and($log[0]->template_code)->toBe('engagement.cart_reminder.sms')->and((int) $log[0]->user_id)->toBe($smsOnly->id);
    });

    it('writes in the language of the account', function () {
        $rina = ($this->customer)('Rina', 'rina@example.com', null, ['email' => true], 'bn');
        ($this->fill)($rina);
        ($this->idle)();
        ($this->run)();

        expect(($this->logs)()->first()->locale)->toBe('bn')
            ->and(app(NotificationSender::class)->send('engagement.cart_reminder.email', new Recipient(null, 'rina@example.com', 'bn', $rina->id), ['shop' => 'S', 'link' => 'L', 'preferences' => 'P'], 'preview-bn')) // renders without error
            ->toBeString();
    });

    it('holds the message until the customer\'s quiet hours end', function () {
        app(SaveMessagePreferences::class)->handle($this->amina->id, ['email' => true], '00:00', '23:59');   // quiet nearly all day (Dhaka time)
        ($this->fill)($this->amina);
        ($this->idle)();
        ($this->run)();

        $row = ($this->logs)()->first();
        expect($row->status)->toBe('queued')->and(CarbonImmutable::parse($row->next_attempt_at, 'UTC')->greaterThan(now('UTC')))->toBeTrue();
    });
});

describe('which carts', function () {
    it('leaves a cart alone that changed a moment ago, one that is empty, and one nobody has touched for days', function () {
        $fresh = ($this->customer)('Fresh', 'fresh@example.com');
        $empty = ($this->customer)('Empty', 'empty@example.com');
        $old = ($this->customer)('Old', 'old@example.com');
        ($this->fill)($old);
        $this->travel(4)->days();                       // 4 days idle: past the 3-day limit
        ($this->fill)($empty);
        ($this->fill)($fresh);
        $this->travel(3)->hours();
        ($this->fill)($fresh);                          // changed just now
        app(CartWriter::class)->clear(CartOwner::user($empty->id));   // what checkout does after the order is placed

        expect(($this->run)())->toBe(0)->and(($this->claims)())->toBe(0);
    });

    it('never lists a guest cart', function () {
        app(AddToCart::class)->handle(CartOwner::guest('guest-token-1'), $this->tee['variantId'], 1);
        ($this->idle)();

        expect(app(AbandonedCarts::class)->idle(CarbonImmutable::now('UTC')->subHours(2), CarbonImmutable::now('UTC')->subDays(3), 100))->toBe([]);
    });

    it('goes through every page of carts, not only the first', function () {
        $a = ($this->customer)('A', 'a@example.com');
        $b = ($this->customer)('B', 'b@example.com');
        ($this->fill)($a);
        $this->travel(1)->minutes();
        ($this->fill)($b);
        ($this->idle)();

        $carts = app(AbandonedCarts::class);
        $first = $carts->idle(CarbonImmutable::now('UTC')->subHours(2), CarbonImmutable::now('UTC')->subDays(3), 1, 0);
        $second = $carts->idle(CarbonImmutable::now('UTC')->subHours(2), CarbonImmutable::now('UTC')->subDays(3), 1, 1);

        expect($first)->toHaveCount(1)->and($second)->toHaveCount(1)->and($first[0]->userId)->toBe($a->id)->and($second[0]->userId)->toBe($b->id);
    });

    it('reminds the first customers even when the earlier carts belong to people who said no', function () {
        // 3 people who never agreed come first; with pages of 2 the yes is only on the second page.
        config(['engagement.cart_recovery.page' => 2]);
        foreach (['x', 'y', 'z'] as $n) {
            ($this->fill)(($this->customer)($n, "{$n}@example.com", null, []));
            $this->travel(1)->minutes();
        }
        ($this->fill)($this->amina);
        ($this->idle)();

        expect(($this->run)())->toBe(1)->and((int) ($this->logs)()->first()->user_id)->toBe($this->amina->id);
    });
});

describe('how often', function () {
    it('reminds once for one cart episode, however often the job runs', function () {
        ($this->fill)($this->amina);
        ($this->idle)();

        expect(($this->run)())->toBe(1)->and(($this->run)())->toBe(0)->and(($this->logs)())->toHaveCount(1);
    });

    it('does not remind again within the gap even if the cart changes again', function () {
        ($this->fill)($this->amina);
        ($this->idle)();
        ($this->run)();
        ($this->fill)($this->amina, $this->lamp);   // a new episode
        ($this->idle)();

        expect(($this->run)())->toBe(0)->and(($this->logs)())->toHaveCount(1);
    });

    it('reminds again for a new episode once the gap has passed', function () {
        ($this->fill)($this->amina);
        ($this->idle)();
        ($this->run)();
        $this->travel(7)->days();
        ($this->fill)($this->amina, $this->lamp);
        ($this->idle)();

        expect(($this->run)())->toBe(1)->and(($this->logs)())->toHaveCount(2)->and(($this->claims)())->toBe(2);
    });

    it('sends no more than the limit in a run and the rest in the next', function () {
        config(['engagement.cart_recovery.per_run' => 2]);
        foreach (['p', 'q', 'r'] as $n) {
            ($this->fill)(($this->customer)($n, "{$n}@example.com"));
        }
        ($this->idle)();

        expect(($this->run)())->toBe(2)->and(($this->run)())->toBe(1)->and(($this->run)())->toBe(0)->and(($this->logs)())->toHaveCount(3);
    });

    it('lets only one of two workers remind the same cart', function () {
        ($this->fill)($this->amina);
        ($this->idle)();
        $cart = app(AbandonedCarts::class)->idle(CarbonImmutable::now('UTC')->subHours(2), CarbonImmutable::now('UTC')->subDays(3), 10)[0];
        $contact = app(CustomerProfiles::class)->findByUserId($this->amina->id);
        $action = app(SendCartReminders::class);

        expect($action->remind($cart, $contact, 'en'))->toBeTrue()
            ->and($action->remind($cart, $contact, 'en'))->toBeFalse();
        // a worker that did not see the first claim (the gap check passes, the unique key stops it)
        DB::table('cart_recovery_messages')->update(['created_at' => now('UTC')->subDays(30)]);

        expect($action->remind($cart, $contact, 'en'))->toBeFalse()->and(($this->logs)())->toHaveCount(1);
    });

    it('links to a language the shop has, never to one the account claims', function () {
        ($this->fill)($this->amina);
        ($this->idle)();
        $cart = app(AbandonedCarts::class)->idle(CarbonImmutable::now('UTC')->subHours(2), CarbonImmutable::now('UTC')->subDays(3), 10)[0];

        $seen = new ArrayObject;
        $capture = new class($seen) implements NotificationSender
        {
            public function __construct(private ArrayObject $seen) {}

            public function send(string $templateCode, Recipient $to, array $variables, string $idempotencyKey, ?string $related = null): string
            {
                $this->seen->exchangeArray(['locale' => $to->locale] + $variables);

                return 'x';
            }
        };
        $action = new SendCartReminders(app(AbandonedCarts::class), app(CustomerProfiles::class), app(AccountLocales::class), app(MarketingConsent::class), $capture, app(Clock::class), app(FeatureFlags::class));

        expect($action->remind($cart, app(CustomerProfiles::class)->findByUserId($this->amina->id), 'xx'))->toBeTrue()
            ->and($seen['locale'])->toBe('en')
            ->and($seen['link'])->toEndWith('/en/cart')
            ->and($seen['preferences'])->toEndWith('/en/account/preferences');
    });

    it('does not remind an account that has no profile', function () {
        ($this->fill)($this->amina);
        ($this->idle)();
        $cart = app(AbandonedCarts::class)->idle(CarbonImmutable::now('UTC')->subHours(2), CarbonImmutable::now('UTC')->subDays(3), 10)[0];

        expect(app(SendCartReminders::class)->remind($cart, null, 'en'))->toBeFalse()->and(($this->claims)())->toBe(0);
    });

    it('claims nothing when the message could not be recorded', function () {
        ($this->fill)($this->amina);
        ($this->idle)();
        $broken = new class implements NotificationSender
        {
            public function send(string $templateCode, Recipient $to, array $variables, string $idempotencyKey, ?string $related = null): string
            {
                throw new RuntimeException('database went away');
            }
        };
        Log::spy();
        $action = new SendCartReminders(app(AbandonedCarts::class), app(CustomerProfiles::class), app(AccountLocales::class), app(MarketingConsent::class), $broken, app(Clock::class), app(FeatureFlags::class));

        expect($action->handle())->toBe(0)->and(($this->claims)())->toBe(0);
        Log::shouldHaveReceived('warning')->with('engagement.cart_reminder_not_recorded', Mockery::type('array'))->once();
        expect(($this->run)())->toBe(1);   // the next run tries again
    });
});

describe('the customer\'s data', function () {
    it('is in the data copy and gone after erasure', function () {
        ($this->fill)($this->amina);
        ($this->idle)();
        ($this->run)();
        $other = ($this->customer)('Bob', 'bob@example.com');
        DB::table('cart_recovery_messages')->insert(['user_id' => $other->id, 'cart_stamp' => '2026-01-01T00:00:00.000000', 'channel' => 'email', 'created_at' => now('UTC')]);

        $export = app(EngagementPersonalData::class)->export($this->amina->id);
        expect($export['cart_reminders'])->toHaveCount(1)->and($export['cart_reminders'][0]['channel'])->toBe('email');

        app(EngagementPersonalData::class)->erase($this->amina->id);
        expect(DB::table('cart_recovery_messages')->where('user_id', $this->amina->id)->count())->toBe(0)
            ->and(DB::table('cart_recovery_messages')->where('user_id', $other->id)->count())->toBe(1);
    });

    it('holds no message text and no address', function () {
        expect(Schema::getColumnListing('cart_recovery_messages'))->toBe(['id', 'user_id', 'cart_stamp', 'channel', 'created_at']);
    });
});

it('is scheduled every fifteen minutes without overlapping', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'engagement:cart-reminders');

    expect($event)->not->toBeNull()->and($event->expression)->toBe('*/15 * * * *')->and($event->withoutOverlapping)->toBeTrue();
});

it('describes the episode by when the newest line changed', function () {
    $cart = new AbandonedCart(1, CarbonImmutable::parse('2026-11-17 10:00:00.123456', 'UTC'));

    expect($cart->stamp())->toBe('2026-11-17T10:00:00.123456')->and(strlen($cart->stamp()))->toBe(26);
});
