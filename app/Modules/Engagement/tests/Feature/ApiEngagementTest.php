<?php

declare(strict_types=1);

use App\Modules\Customers\Contracts\CustomerProfiles;
use App\Modules\Engagement\Actions\ModerateReview;
use App\Modules\IdentityAccess\Actions\IssueApiSession;
use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;

/* Phase 11, slice 6a: saved items, "tell me when it is back", reviews and support for the app. Always the signed-in customer's own. */

beforeEach(function () {
    $this->withoutMiddleware([ThrottleRequests::class, EnforceSessionPolicy::class]);
    shopWorld($this);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->user = function (string $name, string $email): User {
        $user = User::factory()->verified()->create(['name' => $name, 'email' => $email]);
        app(CustomerProfiles::class)->ensureForUser($user->id, $name, $email, null);

        return $user;
    };
    $this->amina = ($this->user)('Amina Rahman', 'amina@example.com');
    $this->bob = ($this->user)('Bob Karim', 'bob@example.com');
    $this->as = function (User $u) {
        auth()->forgetGuards();
        auth()->shouldUse((string) config('auth.defaults.guard'));

        return ['Authorization' => 'Bearer '.app(IssueApiSession::class)->start($u, 'Pixel', 'android', 'password')->accessToken];
    };
    $this->anon = function () {
        auth()->forgetGuards();
        auth()->shouldUse((string) config('auth.defaults.guard'));

        return [];
    };
});

describe('the wishlist', function () {
    it('saves an item once, lists it with its price and stock, and takes it away', function () {
        $this->postJson('/api/v1/me/wishlist', ['variant' => $this->tee['publicId']], ($this->as)($this->amina))->assertNoContent();
        $this->postJson('/api/v1/me/wishlist', ['variant' => $this->tee['publicId']], ($this->as)($this->amina))->assertNoContent();
        expect(DB::table('wishlist_items')->where('user_id', $this->amina->id)->count())->toBe(1);

        $list = $this->getJson('/api/v1/me/wishlist', ($this->as)($this->amina));
        $list->assertOk()->assertJsonPath('data.items.0.variant_id', $this->tee['publicId'])->assertJsonPath('data.items.0.price.amount_minor', 65000)->assertJsonPath('data.items.0.state', 'in_stock')->assertJsonPath('data.items.0.slug', $this->tee['slug']);
        assertMatchesOpenApi($list, 'GET', '/api/v1/me/wishlist');

        $this->deleteJson("/api/v1/me/wishlist/{$this->tee['publicId']}", [], ($this->as)($this->amina))->assertNoContent();
        $this->getJson('/api/v1/me/wishlist', ($this->as)($this->amina))->assertJsonPath('data.items', []);
    });

    it('is each customer’s own: a list is never shared, and removing somebody else’s item changes nothing', function () {
        $this->postJson('/api/v1/me/wishlist', ['variant' => $this->tee['publicId']], ($this->as)($this->amina))->assertNoContent();

        $this->getJson('/api/v1/me/wishlist', ($this->as)($this->bob))->assertJsonPath('data.items', []);
        $this->deleteJson("/api/v1/me/wishlist/{$this->tee['publicId']}", [], ($this->as)($this->bob))->assertNoContent();
        expect(DB::table('wishlist_items')->where('user_id', $this->amina->id)->count())->toBe(1);
    });

    it('refuses an item that does not exist, and a malformed one', function () {
        $this->postJson('/api/v1/me/wishlist', ['variant' => str_repeat('0', 26)], ($this->as)($this->amina))->assertNotFound();
        $this->postJson('/api/v1/me/wishlist', ['variant' => 'short'], ($this->as)($this->amina))->assertStatus(422)->assertJsonValidationErrors(['variant']);
        expect(DB::table('wishlist_items')->count())->toBe(0);
    });

    it('moves an item to the cart at the smallest quantity it allows, or says why it cannot', function () {
        $this->postJson('/api/v1/me/wishlist', ['variant' => $this->lamp['publicId']], ($this->as)($this->amina))->assertNoContent();
        $this->postJson("/api/v1/me/wishlist/{$this->lamp['publicId']}/cart", [], ($this->as)($this->amina))->assertNoContent();
        $this->getJson('/api/v1/cart', ($this->as)($this->amina))->assertJsonPath('data.lines.0.quantity', 2); // sold in twos

        ($this->setStock)($this->tee, 0);
        $this->postJson('/api/v1/me/wishlist', ['variant' => $this->tee['publicId']], ($this->as)($this->amina))->assertNoContent();
        $no = $this->postJson("/api/v1/me/wishlist/{$this->tee['publicId']}/cart", [], ($this->as)($this->amina));
        $no->assertStatus(422)->assertJsonPath('code', 'cart.item_unavailable');
        expect($no->json('detail'))->not->toBeEmpty();
    });
});

describe('tell me when it is back', function () {
    it('can be asked for an item that is out of stock, shows up in the list, and can be taken back', function () {
        ($this->setStock)($this->tee, 0);
        $this->postJson('/api/v1/me/wishlist', ['variant' => $this->tee['publicId']], ($this->as)($this->amina))->assertNoContent();

        $this->postJson('/api/v1/me/stock-alerts', ['variant' => $this->tee['publicId']], ($this->as)($this->amina))->assertNoContent();
        $list = $this->getJson('/api/v1/me/wishlist', ($this->as)($this->amina));
        $list->assertJsonPath('data.items.0.state', 'out_of_stock')->assertJsonPath('data.items.0.alert_set', true)->assertJsonPath('data.alerts.0.variant_id', $this->tee['publicId'])->assertJsonPath('data.alerts.0.slug', $this->tee['slug']);

        $this->deleteJson("/api/v1/me/stock-alerts/{$this->tee['publicId']}", [], ($this->as)($this->amina))->assertNoContent();
        $this->getJson('/api/v1/me/wishlist', ($this->as)($this->amina))->assertJsonPath('data.alerts', []);
    });

    it('is refused for an item that is in stock', function () {
        $this->postJson('/api/v1/me/stock-alerts', ['variant' => $this->tee['publicId']], ($this->as)($this->amina))->assertStatus(422)->assertJsonPath('code', 'request.refused');
        expect(DB::table('stock_alerts')->count())->toBe(0);
    });
});

describe('reviews', function () {
    beforeEach(function () {
        $this->done = ($this->orderFor)($this->amina->id, [$this->tee['variantId'] => 1, $this->ebook['variantId'] => 1]);
        ($this->completeOrder)($this->done->id);
        $this->write = fn (array $over = [], ?User $as = null, $order = null, ?array $v = null) => $this->postJson('/api/v1/orders/'.($order ?? $this->done)->public_id.'/reviews', $over + ['variant' => ($v ?? $this->tee)['publicId'], 'rating' => 5, 'title' => 'Lovely', 'body' => 'Soft cotton and a good fit, I would buy it again.'], ($this->as)($as ?? $this->amina));
    });

    it('lists what I can review in my own order, and nothing for somebody else’s', function () {
        $lines = $this->getJson("/api/v1/orders/{$this->done->public_id}/reviews", ($this->as)($this->amina));
        $lines->assertOk();
        assertMatchesOpenApi($lines, 'GET', "/api/v1/orders/{$this->done->public_id}/reviews");
        expect(collect($lines->json('data'))->pluck('variant_id')->all())->toContain($this->tee['publicId'])->and(collect($lines->json('data'))->pluck('reviewed')->unique()->all())->toBe([false]);

        $this->getJson("/api/v1/orders/{$this->done->public_id}/reviews", ($this->as)($this->bob))->assertNotFound();
    });

    it('takes a review from a buyer, shows it to nobody until the shop approves it, then to everybody with the star counts', function () {
        $made = ($this->write)();
        $made->assertCreated()->assertJsonPath('data.status', 'pending');
        assertMatchesOpenApi($made, 'POST', "/api/v1/orders/{$this->done->public_id}/reviews");

        $public = fn () => $this->getJson('/api/v1/reviews?variant='.$this->tee['publicId'], ($this->anon)());
        $public()->assertOk()->assertJsonPath('data.stats.count', 0)->assertJsonPath('data.reviews', []);

        $staff = catalogStaff(['reviews.moderate']);
        app(ModerateReview::class)->approve(new Actor(ActorType::Staff, $staff->id, null, 'S'), $made->json('data.id'));
        $shown = $public();
        $shown->assertOk()->assertJsonPath('data.stats.count', 1)->assertJsonPath('data.reviews.0.rating', 5)->assertJsonPath('data.reviews.0.author', 'Amina R.');
        assertMatchesOpenApi($shown, 'GET', '/api/v1/reviews?variant='.$this->tee['publicId']);
        expect($shown->getContent())->not->toContain('amina@example.com');

        // the second page of one review is empty, and the links say which page this is
        $second = $this->getJson('/api/v1/reviews?variant='.$this->tee['publicId'].'&page=2', ($this->anon)());
        $second->assertOk()->assertJsonPath('data.reviews', [])->assertJsonPath('meta.page', 2)->assertJsonPath('meta.total', 1)->assertJsonPath('links.next', null);
        expect($second->json('links.prev'))->toContain('page=1');
    });

    it('refuses a second review of the same item, an item not in the order, someone else’s order, and an order not yet complete', function () {
        ($this->write)()->assertCreated();
        ($this->write)()->assertStatus(409)->assertJsonPath('code', 'review.duplicate');
        ($this->write)([], null, null, $this->lamp)->assertNotFound();
        ($this->write)([], $this->bob)->assertNotFound();

        $pending = ($this->orderFor)($this->amina->id, [$this->tee['variantId'] => 1]);
        ($this->write)([], null, $pending)->assertStatus(422)->assertJsonPath('code', 'review.not_eligible');
        expect(DB::table('reviews')->count())->toBe(1);
    });

    it('refuses links, too little text and a rating out of range, naming the field', function () {
        ($this->write)(['body' => 'Great, see http://spam.example for more of it please'])->assertStatus(422)->assertJsonValidationErrors(['body']);
        ($this->write)(['body' => 'short'])->assertStatus(422)->assertJsonValidationErrors(['body']);
        ($this->write)(['rating' => 6])->assertStatus(422)->assertJsonValidationErrors(['rating']);
        ($this->write)(['rating' => 0])->assertStatus(422);
        expect(DB::table('reviews')->count())->toBe(0);
    });

    it('lists my own reviews with their state, and lets me withdraw mine but not anyone else’s', function () {
        $id = ($this->write)()->json('data.id');

        $mine = $this->getJson('/api/v1/me/reviews', ($this->as)($this->amina));
        $mine->assertOk()->assertJsonPath('data.0.status', 'pending')->assertJsonPath('data.0.rating', 5);
        assertMatchesOpenApi($mine, 'GET', '/api/v1/me/reviews');
        $this->getJson('/api/v1/me/reviews', ($this->as)($this->bob))->assertJsonPath('data', []);

        $this->postJson("/api/v1/me/reviews/{$id}/withdraw", [], ($this->as)($this->bob))->assertNotFound();
        $this->postJson("/api/v1/me/reviews/{$id}/withdraw", [], ($this->as)($this->amina))->assertNoContent();
        $this->getJson('/api/v1/me/reviews', ($this->as)($this->amina))->assertJsonPath('data.0.status', 'withdrawn');
    });

    it('answers for an unknown product and a missing variant', function () {
        $this->getJson('/api/v1/reviews?variant='.str_repeat('0', 26), ($this->anon)())->assertNotFound()->assertJsonPath('code', 'product.not_found');
        $this->getJson('/api/v1/reviews', ($this->anon)())->assertStatus(422)->assertJsonValidationErrors(['variant']);
    });
});

describe('support', function () {
    beforeEach(function () {
        $this->open = fn (array $over = [], ?User $as = null) => $this->postJson('/api/v1/support/tickets', $over + ['subject' => 'Where is my order?', 'category' => 'delivery', 'body' => 'It has been a week and nothing has arrived.'], ($this->as)($as ?? $this->amina));
    });

    it('opens a ticket, lists it, shows its messages, takes a reply and can be closed', function () {
        $made = ($this->open)();
        $made->assertCreated();
        assertMatchesOpenApi($made, 'POST', '/api/v1/support/tickets');
        $id = $made->json('data.id');

        $list = $this->getJson('/api/v1/support/tickets', ($this->as)($this->amina));
        $list->assertOk()->assertJsonPath('data.tickets.0.id', $id)->assertJsonPath('data.tickets.0.status', fn ($s) => is_string($s));
        assertMatchesOpenApi($list, 'GET', '/api/v1/support/tickets');
        expect($list->json('data.categories'))->toContain('delivery', 'payment');

        $this->postJson("/api/v1/support/tickets/{$id}/messages", ['body' => 'Any news at all, please?'], ($this->as)($this->amina))->assertNoContent();
        $thread = $this->getJson("/api/v1/support/tickets/{$id}", ($this->as)($this->amina));
        $thread->assertOk()->assertJsonCount(2, 'data.messages')->assertJsonPath('data.messages.0.mine', true)->assertJsonPath('data.messages.1.body', 'Any news at all, please?');
        assertMatchesOpenApi($thread, 'GET', "/api/v1/support/tickets/{$id}");

        $this->postJson("/api/v1/support/tickets/{$id}/close", [], ($this->as)($this->amina))->assertNoContent();
        $this->getJson("/api/v1/support/tickets/{$id}", ($this->as)($this->amina))->assertJsonPath('data.ticket.status', 'closed');
    });

    it('ties a ticket to one of my orders only', function () {
        $mine = ($this->orderFor)($this->amina->id, [$this->tee['variantId'] => 1]);
        $theirs = ($this->orderFor)($this->bob->id, [$this->tee['variantId'] => 1]);

        $ok = ($this->open)(['order' => $mine->public_id]);
        $ok->assertCreated();
        $this->getJson('/api/v1/support/tickets/'.$ok->json('data.id'), ($this->as)($this->amina))->assertJsonPath('data.order.number', $mine->order_number);
        ($this->open)(['order' => $theirs->public_id])->assertStatus(422)->assertJsonPath('code', 'request.refused');
        expect(DB::table('support_tickets')->count())->toBe(1);
    });

    it('is the customer’s own: somebody else’s ticket is 404 to read, answer or close', function () {
        $id = ($this->open)()->json('data.id');

        $this->getJson("/api/v1/support/tickets/{$id}", ($this->as)($this->bob))->assertNotFound();
        $this->postJson("/api/v1/support/tickets/{$id}/messages", ['body' => 'Not yours to answer'], ($this->as)($this->bob))->assertNotFound();
        $this->postJson("/api/v1/support/tickets/{$id}/close", [], ($this->as)($this->bob))->assertNotFound();
        $this->getJson('/api/v1/support/tickets', ($this->as)($this->bob))->assertJsonPath('data.tickets', []);
        expect(DB::table('support_messages')->count())->toBe(1);
    });

    it('refuses a short subject, a short message and an unknown kind, naming the field', function () {
        ($this->open)(['subject' => 'Hi'])->assertStatus(422)->assertJsonValidationErrors(['subject']);
        ($this->open)(['body' => 'hey'])->assertStatus(422)->assertJsonValidationErrors(['body']);
        ($this->open)(['category' => 'gossip'])->assertStatus(422)->assertJsonValidationErrors(['category']);
        expect(DB::table('support_tickets')->count())->toBe(0);
    });

    it('lets a reply reopen a closed ticket for a while and then closes it for good; and needs a signed-in customer', function () {
        $id = ($this->open)()->json('data.id');
        $this->postJson("/api/v1/support/tickets/{$id}/close", [], ($this->as)($this->amina))->assertNoContent();
        $this->postJson("/api/v1/support/tickets/{$id}/messages", ['body' => 'One more thing please'], ($this->as)($this->amina))->assertNoContent();
        $this->getJson("/api/v1/support/tickets/{$id}", ($this->as)($this->amina))->assertJsonPath('data.ticket.status', 'open');

        $this->postJson("/api/v1/support/tickets/{$id}/close", [], ($this->as)($this->amina))->assertNoContent();
        $this->travel(15)->days();
        $late = $this->postJson("/api/v1/support/tickets/{$id}/messages", ['body' => 'Much later, still there?'], ($this->as)($this->amina));
        $late->assertStatus(422)->assertJsonPath('code', 'request.refused');
        expect($late->json('detail'))->not->toBeEmpty();
        $this->travelBack();

        $this->getJson('/api/v1/support/tickets', ($this->anon)())->assertStatus(401);
        $this->getJson('/api/v1/me/wishlist', ($this->anon)())->assertStatus(401);
    });
});
