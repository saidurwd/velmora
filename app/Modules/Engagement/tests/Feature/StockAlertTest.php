<?php

declare(strict_types=1);

use App\Modules\Catalog\Actions\TransitionProduct;
use App\Modules\Catalog\Contracts\PurchasableVariants;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Customers\Contracts\CustomerProfiles;
use App\Modules\Engagement\Actions\NotifyBackInStock;
use App\Modules\Engagement\Actions\SubscribeStockAlert;
use App\Modules\Engagement\Exceptions\EngagementException;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Notifications\Contracts\NotificationSender;
use App\Modules\Notifications\DTOs\Recipient;
use App\Modules\Notifications\Mail\NotificationMail;
use App\Modules\Notifications\Sms\FakeSmsGateway;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    shopWorld($this);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    Mail::fake();
    config(['services.sms.driver' => 'fake']);
    $this->sms = app(FakeSmsGateway::class);
    $this->sms->fail = false;
    /** A customer with a profile; email and phone are optional. */
    $this->customer = function (string $name, ?string $email, ?string $phone = null): User {
        $user = User::factory()->create(['name' => $name, 'email' => $email ?? 'none-'.uniqid().'@example.com', 'phone' => $phone]);
        app(CustomerProfiles::class)->ensureForUser($user->id, $name, $email, $phone);

        return $user;
    };
    $this->amina = ($this->customer)('Amina', 'amina@example.com', '+8801711111111');
    $this->bob = ($this->customer)('Bob', 'bob@example.com');
    ($this->setStock)($this->tee, 0);
    $this->ask = fn (?User $as = null, ?array $v = null, string $locale = 'en') => $this->actingAs($as ?? $this->amina, 'web')->post("/{$locale}/account/stock-alerts", ['variant' => ($v ?? $this->tee)['publicId']]);
    $this->alerts = fn (?array $v = null) => DB::table('stock_alerts')->where('variant_id', ($v ?? $this->tee)['variantId'])->orderBy('id')->get(['user_id', 'status', 'locale'])->map(fn ($a) => "{$a->user_id}/{$a->status}/{$a->locale}")->all();
    $this->run = fn () => app(NotifyBackInStock::class)->handle();
});

describe('asking to be told', function () {
    it('is for signed-in customers', function () {
        $this->post('/en/account/stock-alerts', ['variant' => $this->tee['publicId']])->assertRedirect('/en/login');
        $this->delete('/en/account/stock-alerts/'.$this->tee['publicId'])->assertRedirect('/en/login');

        expect(DB::table('stock_alerts')->count())->toBe(0);
    });

    it('waits for an item that is out of stock, once, in the customer\'s language', function () {
        ($this->ask)()->assertSessionHas('status');
        ($this->ask)();
        ($this->ask)(null, null, 'bn');   // asking again while waiting changes nothing

        expect(($this->alerts)())->toBe([$this->amina->id.'/pending/en']);
    });

    it('refuses an item that is in stock, or a service, with a reason', function () {
        ($this->ask)(null, $this->lamp)->assertSessionHasErrors('wishlist');   // 20 in stock
        $service = ($this->make)('Tailoring', 'দর্জি', 'SVC-1', 500, 0, ProductType::Service);
        ($this->ask)(null, $service)->assertSessionHasErrors('wishlist');
        $ebook = ($this->ask)(null, $this->ebook);   // a digital item is never "out of stock"
        $ebook->assertSessionHasErrors('wishlist');

        expect(DB::table('stock_alerts')->count())->toBe(0);
    });

    it('stops at the most alerts one customer may wait for', function () {
        config(['engagement.alerts_max' => 1]);
        $other = ($this->make)('Other', 'অন্য', 'OTHER-1', 100, 0);

        ($this->ask)();
        ($this->ask)(null, $other)->assertSessionHasErrors('wishlist');

        expect(DB::table('stock_alerts')->count())->toBe(1);
    });

    it('can be cancelled, but only by the customer who asked', function () {
        ($this->ask)();
        ($this->ask)($this->bob);

        $this->actingAs($this->amina, 'web')->delete('/en/account/stock-alerts/'.$this->tee['publicId'])->assertSessionHas('status');

        expect(($this->alerts)())->toBe([$this->bob->id.'/pending/en']);
    });

    it('is shown on the wishlist page, with the item\'s name and a way to stop', function () {
        ($this->ask)();

        $this->actingAs($this->amina, 'web')->get('/en/account/wishlist')->assertOk()->assertSee('Waiting for stock')->assertSee('Cotton Tee')->assertSee('Stop telling me')
            ->assertDontSee('Brass Lamp');
        $this->actingAs($this->bob, 'web')->get('/en/account/wishlist')->assertDontSee('Cotton Tee');
    });

    it('is offered on the product page of an item that is out of stock', function () {
        $this->get('/en/p/'.$this->tee['slug'])->assertSee('Sign in to be told when it is back');
        $this->actingAs($this->amina, 'web')->get('/en/p/'.$this->tee['slug'])->assertOk()->assertSee('Tell me when it is back');
        $this->actingAs($this->amina, 'web')->get('/en/p/'.$this->lamp['slug'])->assertOk()->assertDontSee('Tell me when it is back');
    });
});

describe('telling them', function () {
    it('says nothing while the item is out of stock, then tells the person once when it is back', function () {
        ($this->ask)();
        expect(($this->run)())->toBe(0);

        ($this->setStock)($this->tee, 4);
        expect(($this->run)())->toBe(1)->and(($this->run)())->toBe(0);

        Mail::assertSent(NotificationMail::class, 1);
        Mail::assertSent(NotificationMail::class, fn (NotificationMail $m) => $m->hasTo('amina@example.com') && $m->subjectLine === 'Cotton Tee is back in stock' && str_contains($m->text, '/en/p/'.$this->tee['slug']));
        expect($this->sms->sent())->toHaveCount(0)->and(($this->alerts)())->toBe([$this->amina->id.'/notified/en'])
            ->and(DB::table('notification_logs')->where('template_code', 'like', 'engagement.back_in_stock%')->where('kind', 'transactional')->count())->toBe(1);
    });

    it('sends one message, by email when there is one and otherwise by text, never both', function () {
        $phoneOnly = ($this->customer)('Karim', null, '+8801733333333');
        ($this->ask)($this->amina);
        ($this->ask)($phoneOnly);
        ($this->setStock)($this->tee, 10);

        expect(($this->run)())->toBe(2);

        Mail::assertSent(NotificationMail::class, 1);
        expect($this->sms->sent())->toHaveCount(1)->and($this->sms->sent()[0]['to'])->toBe('+8801733333333')->and($this->sms->sent()[0]['message'])->toContain('Cotton Tee is back in stock');
    });

    it('writes the message in the language the customer asked in', function () {
        ($this->ask)(null, null, 'bn');
        ($this->setStock)($this->tee, 3);
        ($this->run)();

        Mail::assertSent(NotificationMail::class, fn (NotificationMail $m) => str_contains($m->subjectLine, 'আবার স্টকে এসেছে') && str_contains($m->subjectLine, 'সুতির টি-শার্ট') && str_contains($m->text, '/bn/p/'));
    });

    it('does not tell more people than there are units to go round, oldest first, and the rest wait', function () {
        $people = [($this->customer)('P1', 'p1@example.com'), ($this->customer)('P2', 'p2@example.com'), ($this->customer)('P3', 'p3@example.com'), ($this->customer)('P4', 'p4@example.com'), ($this->customer)('P5', 'p5@example.com')];
        foreach ($people as $p) {
            ($this->ask)($p);
        }
        ($this->setStock)($this->tee, 1);   // one unit: two people (twice the units)

        expect(($this->run)())->toBe(2);
        expect(array_map(fn ($u) => DB::table('stock_alerts')->where('user_id', $u->id)->value('status'), $people))->toBe(['notified', 'notified', 'pending', 'pending', 'pending']);

        expect(($this->run)())->toBe(2)->and(($this->run)())->toBe(1)->and(($this->run)())->toBe(0);   // while stock lasts, the next ones are told
    });

    it('can be asked for again after being told', function () {
        ($this->ask)();
        ($this->setStock)($this->tee, 2);
        ($this->run)();
        ($this->setStock)($this->tee, 0);

        ($this->ask)()->assertSessionHasNoErrors();
        expect(($this->alerts)())->toBe([$this->amina->id.'/pending/en']);
        ($this->setStock)($this->tee, 2);
        expect(($this->run)())->toBe(1);
        Mail::assertSent(NotificationMail::class, 2);
    });

    it('does not announce an item that is no longer on sale, and keeps waiting', function () {
        ($this->ask)();
        ($this->setStock)($this->tee, 5);
        app(TransitionProduct::class)->archive($this->actor, $this->tee['product']);

        expect(($this->run)())->toBe(0)->and(($this->alerts)())->toBe([$this->amina->id.'/pending/en']);
        Mail::assertNothingSent();
    });

    it('closes an alert for someone we cannot reach, without sending anything', function () {
        $ghost = User::factory()->create(['email' => 'ghost@example.com']);   // no profile: no email or phone on file
        ($this->ask)($ghost);
        ($this->setStock)($this->tee, 2);

        expect(($this->run)())->toBe(0)->and(($this->alerts)())->toBe([$ghost->id.'/notified/en']);
        Mail::assertNothingSent();
    });

    it('is told only once even if two workers run at the same moment', function () {
        ($this->ask)();
        ($this->setStock)($this->tee, 5);
        $alert = DB::table('stock_alerts')->first();
        // another worker got there first, between our look and our claim
        DB::table('stock_alerts')->where('id', $alert->id)->update(['status' => 'notified', 'notified_at' => now(), 'updated_at' => now()]);

        expect(($this->run)())->toBe(0);
        Mail::assertNothingSent();
    });

    it('lets only one of two workers tell the same person, even when both read the alert before either claimed it', function () {
        ($this->ask)();
        ($this->setStock)($this->tee, 5);
        $stale = DB::table('stock_alerts')->first();   // both workers read it while it was still waiting
        $variant = app(PurchasableVariants::class)->details([$this->tee['variantId']], 'en')[$this->tee['variantId']];
        $contact = app(CustomerProfiles::class)->findByUserId($this->amina->id);

        $first = app(NotifyBackInStock::class)->tell($stale, $variant, $contact);
        $this->travel(2)->seconds();   // the second worker is a moment later, as a real one would be
        $second = app(NotifyBackInStock::class)->tell($stale, $variant, $contact);

        expect($first)->toBeTrue()->and($second)->toBeFalse();
        Mail::assertSent(NotificationMail::class, 1);
        expect(DB::table('notification_logs')->where('template_code', 'like', 'engagement.back_in_stock%')->count())->toBe(1);
    });

    it('leaves the alert waiting, and carries on, when the message cannot be recorded', function () {
        Log::spy();
        $second = ($this->customer)('Second', 'second@example.com');
        ($this->ask)();
        ($this->ask)($second);
        ($this->setStock)($this->tee, 5);
        $calls = new ArrayObject(['n' => 0]);
        $this->app->instance(NotificationSender::class, new class($calls) implements NotificationSender
        {
            public function __construct(public ArrayObject $calls) {}

            public function send(string $templateCode, Recipient $to, array $variables, string $idempotencyKey, ?string $related = null): string
            {
                $this->calls['n']++;
                if ($this->calls['n'] === 1) {
                    throw new RuntimeException('could not record for amina@example.com');
                }

                return 'id';
            }
        });

        expect(($this->run)())->toBe(1);

        expect(DB::table('stock_alerts')->where('user_id', $this->amina->id)->value('status'))->toBe('pending')->and(DB::table('stock_alerts')->where('user_id', $second->id)->value('status'))->toBe('notified');
        Log::shouldHaveReceived('warning')->withArgs(fn ($m, $c = []) => $m === 'engagement.stock_alert_not_recorded' && ! str_contains(json_encode($c), 'amina@example.com'))->once();
    });

    it('runs every five minutes on the schedule', function () {
        Artisan::call('schedule:list');

        expect(Artisan::output())->toMatch('~\*/5\s+\*\s+\*\s+\*\s+\*\s+.*engagement:back-in-stock~');
    });
});

describe('the subscribe action', function () {
    it('refuses to wait for something that is in stock, called directly', function () {
        ($this->setStock)($this->tee, 3);

        expect(fn () => app(SubscribeStockAlert::class)->handle($this->amina->id, $this->tee['publicId'], 'en'))->toThrow(EngagementException::class);
    });
});
