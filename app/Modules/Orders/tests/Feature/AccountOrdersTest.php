<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\IdentityAccess\Notifications\AccountAlreadyExistsNotification;
use App\Modules\IdentityAccess\Notifications\VerifyEmailNotification;
use App\Modules\Orders\Actions\CancelOwnOrder;
use App\Modules\Orders\Actions\LinkOrderToCustomer;
use App\Modules\Orders\Exceptions\OrderException;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Database\Seeders\PaymentMethodSeeder;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    $this->seed([LanguageSeeder::class, PaymentMethodSeeder::class]);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    $this->ann = User::factory()->create(['email' => 'ann@example.com']);
    $this->bob = User::factory()->create(['email' => 'bob@example.com']);
    $this->as = fn (?User $u = null) => $this->actingAs($u ?? $this->ann, 'web');

    /** An order for the given customer (null = guest). Returns its public id. */
    $this->order = function (int $id, ?User $owner = null, array $over = []): string {
        fakeOrders([$id], array_replace(['user_id' => $owner?->id, 'subtotal_minor' => 150000, 'grand_total_minor' => 150000, 'contact_email' => 'guest@example.com'], $over));

        return (string) DB::table('orders')->where('id', $id)->value('public_id');
    };
    $this->thanks = fn (string $publicId) => URL::temporarySignedRoute('checkout.thanks', now()->addDay(), ['locale' => 'en', 'order' => $publicId]);
    $this->signed = fn (string $route, string $publicId, $when = null) => URL::temporarySignedRoute($route, $when ?? now()->addDay(), ['locale' => 'en', 'order' => $publicId]);
    $this->fresh = fn (int $id = 1) => DB::table('orders')->where('id', $id)->first(['user_id', 'status', 'payment_status']);
    $this->form = fn (array $over = []) => array_replace(['name' => 'Rahim Uddin', 'email' => 'new@example.com', 'password' => 'Str0ng!Passw0rd-xyz', 'password_confirmation' => 'Str0ng!Passw0rd-xyz'], $over);
});

describe('My orders', function () {
    it('sends guests to sign in', function () {
        $id = ($this->order)(1, $this->ann);

        $this->get('/en/account/orders')->assertRedirect();
        $this->get("/en/account/orders/{$id}")->assertRedirect();
        $this->post("/en/account/orders/{$id}/cancel")->assertRedirect();
    });

    it('lists only my own orders, newest first, and nobody else\'s', function () {
        ($this->order)(1, $this->ann);
        ($this->order)(2, $this->bob);
        ($this->order)(3, $this->ann);
        ($this->order)(4, null);

        $page = ($this->as)()->get('/en/account/orders')->assertOk()->assertSee('VM-TEST-000001')->assertSee('VM-TEST-000003')->assertDontSee('VM-TEST-000002')->assertDontSee('VM-TEST-000004');

        expect(strpos($page->getContent(), 'VM-TEST-000003'))->toBeLessThan(strpos($page->getContent(), 'VM-TEST-000001'));
    });

    it('says so when there are no orders, and pages the list', function () {
        ($this->as)()->get('/en/account/orders')->assertOk()->assertSee('You have no orders yet.');

        foreach (range(1, 12) as $i) {
            ($this->order)($i, $this->ann);
        }
        ($this->as)()->get('/en/account/orders')->assertOk()->assertSee('VM-TEST-000012')->assertDontSee('VM-TEST-000001<')->assertSee('page=2', false);
        ($this->as)()->get('/en/account/orders?page=2')->assertOk()->assertSee('VM-TEST-000001');
    });

    it('does not run more queries for a longer list', function () {
        ($this->order)(1, $this->ann);
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            ($this->as)()->get('/en/account/orders')->assertOk();

            return count(DB::getQueryLog());
        };
        $count();
        $few = $count();
        foreach (range(2, 9) as $i) {
            ($this->order)($i, $this->ann);
        }

        expect($count())->toBe($few);
    });

    it('shows my order in English and Bangla', function () {
        $id = ($this->order)(1, $this->ann);

        ($this->as)()->get("/en/account/orders/{$id}")->assertOk()->assertSee('VM-TEST-000001')->assertSee('1,500.00')->assertSee('Cancel this order');
        ($this->as)()->get("/bn/account/orders/{$id}")->assertOk()->assertSee('এই অর্ডার বাতিল করুন');
        ($this->as)()->get('/bn/account/orders')->assertOk()->assertSee('আমার অর্ডার');
    });

    it('treats another customer\'s order, a guest order and an unknown order alike: not found', function () {
        $bobs = ($this->order)(1, $this->bob);
        $guest = ($this->order)(2, null);

        foreach ([$bobs, $guest, '01HZZZZZZZZZZZZZZZZZZZZZZZ'] as $id) {
            ($this->as)()->get("/en/account/orders/{$id}")->assertNotFound();
            ($this->as)()->post("/en/account/orders/{$id}/cancel")->assertRedirect()->assertSessionHasErrors('cancel');
        }

        expect(($this->fresh)(1)->status)->toBe('pending')->and(($this->fresh)(2)->status)->toBe('pending');
    });

    it('shows messages the shop meant for the customer, and keeps internal notes private', function () {
        $id = ($this->order)(1, $this->ann);
        DB::table('order_notes')->insert([['order_id' => 1, 'author_id' => null, 'visibility' => 'customer', 'body' => 'We called, delivery on Friday', 'created_at' => now()], ['order_id' => 1, 'author_id' => null, 'visibility' => 'internal', 'body' => 'Customer was rude on the phone', 'created_at' => now()]]);

        ($this->as)()->get("/en/account/orders/{$id}")->assertOk()->assertSee('We called, delivery on Friday')->assertDontSee('Customer was rude');
    });

    it('escapes what the shop and the customer typed', function () {
        $id = ($this->order)(1, $this->ann, ['contact_name' => '<script>alert(1)</script>']);
        DB::table('order_notes')->insert(['order_id' => 1, 'author_id' => null, 'visibility' => 'customer', 'body' => '<img src=x onerror=alert(2)>', 'created_at' => now()]);

        ($this->as)()->get("/en/account/orders/{$id}")->assertOk()->assertDontSee('<img src=x', false)->assertDontSee('<script>alert(1)', false);
    });
});

describe('paying again', function () {
    it('offers a signed pay link only for an unpaid online order', function () {
        $online = ($this->order)(1, $this->ann, ['payment_method_code' => 'online']);
        $cod = ($this->order)(2, $this->ann, ['payment_method_code' => 'cod']);
        $paid = ($this->order)(3, $this->ann, ['payment_method_code' => 'online', 'payment_status' => 'paid']);

        ($this->as)()->get("/en/account/orders/{$online}")->assertOk()->assertSee('Pay now')->assertSee('/pay/order/'.$online, false)->assertSee('signature=', false);
        ($this->as)()->get("/en/account/orders/{$cod}")->assertOk()->assertDontSee('Pay now');
        ($this->as)()->get("/en/account/orders/{$paid}")->assertOk()->assertDontSee('Pay now')->assertDontSee('Pay again');
    });

    it('says a payment is being checked when one is on its way', function () {
        $id = ($this->order)(1, $this->ann, ['payment_method_code' => 'online', 'payment_status' => 'pending']);

        ($this->as)()->get("/en/account/orders/{$id}")->assertOk()->assertSee('Your payment is being checked')->assertSee('Pay again');
    });
});

describe('cancelling my own order', function () {
    it('cancels a pending unpaid order and gives the stock back through the normal path', function () {
        $id = ($this->order)(1, $this->ann);

        ($this->as)()->post("/en/account/orders/{$id}/cancel", ['note' => 'Ordered by mistake'])->assertRedirect()->assertSessionHas('status');

        $order = DB::table('orders')->first();
        expect($order->status)->toBe('cancelled')->and($order->cancel_reason)->toBe('Cancelled by the customer: Ordered by mistake')->and(DB::table('order_status_history')->where('order_id', 1)->where('to_state', 'cancelled')->count())->toBe(1);
        ($this->as)()->get("/en/account/orders/{$id}")->assertOk()->assertDontSee('Cancel this order');
    });

    it('refuses a second cancel, and orders that are confirmed, being paid, paid or shipped', function () {
        $cases = ['confirmed' => ['status' => 'confirmed'], 'paying' => ['payment_status' => 'pending'], 'paid' => ['payment_status' => 'paid'], 'shipped' => ['fulfillment_status' => 'fulfilled']];
        $n = 0;
        foreach ($cases as $label => $over) {
            $id = ($this->order)(++$n, $this->ann, $over);
            ($this->as)()->post("/en/account/orders/{$id}/cancel")->assertSessionHasErrors('cancel');
            ($this->as)()->get("/en/account/orders/{$id}")->assertOk()->assertDontSee('Cancel this order', false);
            expect(($this->fresh)($n)->status)->not->toBe('cancelled', $label);
        }

        $id = ($this->order)(++$n, $this->ann);
        ($this->as)()->post("/en/account/orders/{$id}/cancel")->assertSessionHas('status');
        ($this->as)()->post("/en/account/orders/{$id}/cancel")->assertSessionHasErrors('cancel');
    });

    it('says why in plain words when a payment is on its way', function () {
        $id = ($this->order)(1, $this->ann, ['payment_status' => 'pending']);

        ($this->as)()->post("/en/account/orders/{$id}/cancel")->assertSessionHasErrors(['cancel' => 'A payment is on its way for this order, so it cannot be cancelled right now. Please check again in a few minutes.']);
    });

    it('decides by the order alone, in one place', function () {
        ($this->order)(1, $this->ann);
        $order = Order::query()->first();

        expect(CancelOwnOrder::deniedReason($order))->toBeNull();

        $actor = new Actor(ActorType::Customer, $this->bob->id, null);
        expect(fn () => app(CancelOwnOrder::class)->handle($actor, $order->public_id, null))->toThrow(OrderException::class, 'order_not_found');
    });

    it('validates the note', function () {
        $id = ($this->order)(1, $this->ann);

        ($this->as)()->post("/en/account/orders/{$id}/cancel", ['note' => str_repeat('x', 151)])->assertSessionHasErrors('note');
        expect(($this->fresh)()->status)->toBe('pending');
    });
});

describe('making an account from the confirmation page', function () {
    it('shows the right offer: sign-up for a guest, add-to-account when signed in, a link when it is already mine, nothing when it is someone else\'s', function () {
        $guest = ($this->order)(1, null);
        $mine = ($this->order)(2, $this->ann);
        $bobs = ($this->order)(3, $this->bob);

        $this->get(($this->thanks)($guest))->assertOk()->assertSee('Keep track of this order')->assertSee('Create account');
        ($this->as)()->get(($this->thanks)($guest))->assertOk()->assertSee('Add this order to my account')->assertDontSee('Create account');
        ($this->as)()->get(($this->thanks)($mine))->assertOk()->assertSee('See this order in My orders');
        ($this->as)()->get(($this->thanks)($bobs))->assertOk()->assertDontSee('Keep track of this order')->assertDontSee('Add this order to my account')->assertDontSee('See this order in My orders');
        $this->get(str_replace('/en/', '/bn/', ($this->thanks)($guest)))->assertStatus(403);   // a link signed for one address does not work for another
    });

    it('creates the account, sends the verification email and puts the order in it', function () {
        Notification::fake();
        $id = ($this->order)(1, null);

        $this->post(($this->signed)('checkout.account.create', $id), ($this->form)())->assertRedirect()->assertSessionHas('account_status', 'started');

        $user = User::query()->where('email', 'new@example.com')->first();
        expect($user)->not->toBeNull()->and($user->email_verified_at)->toBeNull()->and((int) ($this->fresh)()->user_id)->toBe($user->id)->and(DB::table('audit_events')->where('action', 'order.linked_to_account')->count())->toBe(1);
        Notification::assertSentTo($user, VerifyEmailNotification::class);
        $this->get(($this->thanks)($id))->assertOk();
    });

    it('answers the same when the address already has an account, and does not touch that account or the order', function () {
        Notification::fake();
        $id = ($this->order)(1, null);

        $this->post(($this->signed)('checkout.account.create', $id), ($this->form)(['email' => 'ann@example.com']))->assertRedirect()->assertSessionHas('account_status', 'started');

        expect(($this->fresh)()->user_id)->toBeNull()->and(User::query()->where('email', 'ann@example.com')->count())->toBe(1);
        Notification::assertSentTo($this->ann, AccountAlreadyExistsNotification::class);
    });

    it('refuses a weak password, a missing field, a bad email and an unsigned or expired link', function () {
        Notification::fake();
        $id = ($this->order)(1, null);
        $url = ($this->signed)('checkout.account.create', $id);

        $this->post($url, ($this->form)(['password' => 'short', 'password_confirmation' => 'short']))->assertSessionHasErrors('password');
        $this->post($url, ($this->form)(['password_confirmation' => 'different']))->assertSessionHasErrors('password');
        $this->post($url, ($this->form)(['email' => 'nope']))->assertSessionHasErrors('email');
        $this->post($url, ($this->form)(['name' => '']))->assertSessionHasErrors('name');
        $this->post("/en/orders/{$id}/account", ($this->form)())->assertForbidden();
        $this->post(($this->signed)('checkout.account.create', $id, now()->subMinute()), ($this->form)())->assertForbidden();
        $this->post(str_replace($id, '01HZZZZZZZZZZZZZZZZZZZZZZZ', $url), ($this->form)())->assertForbidden();   // the signature covers the order

        expect(User::query()->where('email', 'new@example.com')->exists())->toBeFalse()->and(($this->fresh)()->user_id)->toBeNull();
    });

    it('does not create an account for an order that already has an owner, and says nothing different', function () {
        Notification::fake();
        $id = ($this->order)(1, $this->bob);

        $this->post(($this->signed)('checkout.account.create', $id), ($this->form)())->assertRedirect()->assertSessionHas('account_status', 'started');

        expect(User::query()->where('email', 'new@example.com')->exists())->toBeFalse()->and((int) ($this->fresh)()->user_id)->toBe($this->bob->id);
    });
});

describe('adding a guest order to my existing account', function () {
    it('adds the order to the signed-in customer', function () {
        $id = ($this->order)(1, null);

        ($this->as)()->post(($this->signed)('checkout.account.attach', $id))->assertRedirect("/en/account/orders/{$id}")->assertSessionHas('status');

        expect((int) ($this->fresh)()->user_id)->toBe($this->ann->id);
        ($this->as)()->get('/en/account/orders')->assertOk()->assertSee('VM-TEST-000001');
    });

    it('is harmless to do twice, and refuses an order that belongs to someone else', function () {
        $id = ($this->order)(1, null);
        ($this->as)()->post(($this->signed)('checkout.account.attach', $id))->assertSessionHas('status');
        ($this->as)()->post(($this->signed)('checkout.account.attach', $id))->assertSessionHas('status');

        ($this->as)($this->bob)->post(($this->signed)('checkout.account.attach', $id))->assertRedirect()->assertSessionHasErrors('account');

        expect((int) ($this->fresh)()->user_id)->toBe($this->ann->id);
    });

    it('needs sign-in and a signed link', function () {
        $id = ($this->order)(1, null);

        $this->post(($this->signed)('checkout.account.attach', $id))->assertRedirect();
        ($this->as)()->post("/en/orders/{$id}/attach")->assertForbidden();
        ($this->as)()->post(($this->signed)('checkout.account.attach', $id, now()->subMinute()))->assertForbidden();

        expect(($this->fresh)()->user_id)->toBeNull();
    });
});

describe('the owner of an order', function () {
    it('can be set once and never changed, even by a bug', function () {
        ($this->order)(1, null);
        ($this->order)(2, $this->ann);

        expect(DB::table('orders')->where('id', 1)->update(['user_id' => $this->ann->id]))->toBe(1);
        expect(fn () => DB::table('orders')->where('id', 1)->update(['user_id' => $this->bob->id]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('orders')->where('id', 1)->update(['user_id' => null]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('orders')->where('id', 2)->update(['user_id' => $this->bob->id]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('orders')->where('id', 2)->update(['grand_total_minor' => 1]))->toThrow(QueryException::class);
    });

    it('is never taken from one account to another by the Action', function () {
        $id = ($this->order)(1, $this->ann);

        expect(fn () => app(LinkOrderToCustomer::class)->handle(Actor::guest('t'), $id, $this->bob->id))->toThrow(OrderException::class, 'order_already_linked')
            ->and(fn () => app(LinkOrderToCustomer::class)->handle(Actor::guest('t'), '01HZZZZZZZZZZZZZZZZZZZZZZZ', $this->bob->id))->toThrow(OrderException::class, 'order_not_found');
        app(LinkOrderToCustomer::class)->handle(Actor::guest('t'), $id, $this->ann->id);   // same owner: nothing to do

        expect((int) ($this->fresh)()->user_id)->toBe($this->ann->id);
    });
});
