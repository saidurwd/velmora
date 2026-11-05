<?php

declare(strict_types=1);

use App\Modules\Cart\Models\Cart;
use App\Modules\Cart\Models\CartItem;
use App\Modules\Catalog\Actions\TransitionProduct;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Engagement\Actions\AddToWishlist;
use App\Modules\Engagement\Exceptions\EngagementException;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    shopWorld($this);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    $this->amina = User::factory()->create(['email' => 'amina@example.com']);
    $this->bob = User::factory()->create(['email' => 'bob@example.com']);
    $this->service = ($this->make)('Tailoring', 'দর্জি', 'SVC-1', 500, 0, ProductType::Service);
    $this->save = fn (array $v, ?User $as = null) => $this->actingAs($as ?? $this->amina, 'web')->post('/en/account/wishlist', ['variant' => $v['publicId']]);
    $this->saved = fn (?User $u = null) => DB::table('wishlist_items')->where('user_id', ($u ?? $this->amina)->id)->orderBy('id')->pluck('variant_id')->map(fn ($v) => (int) $v)->all();
});

describe('saving for later', function () {
    it('is for signed-in customers', function () {
        $this->post('/en/account/wishlist', ['variant' => $this->tee['publicId']])->assertRedirect('/en/login');
        $this->get('/en/account/wishlist')->assertRedirect('/en/login');
        $this->delete('/en/account/wishlist/'.$this->tee['publicId'])->assertRedirect('/en/login');
        $this->post('/en/account/wishlist/'.$this->tee['publicId'].'/cart')->assertRedirect('/en/login');

        expect(DB::table('wishlist_items')->count())->toBe(0);
    });

    it('saves an item once, however many times it is asked', function () {
        ($this->save)($this->tee)->assertSessionHas('status');
        ($this->save)($this->tee)->assertSessionHasNoErrors();
        ($this->save)($this->tee);

        expect(($this->saved)())->toBe([$this->tee['variantId']]);
    });

    it('keeps each customer\'s list apart, and never takes the customer from the request', function () {
        ($this->actingAs($this->amina, 'web'))->post('/en/account/wishlist', ['variant' => $this->tee['publicId'], 'user_id' => $this->bob->id, 'user' => $this->bob->public_id]);
        ($this->save)($this->lamp, $this->bob);

        expect(($this->saved)($this->amina))->toBe([$this->tee['variantId']])->and(($this->saved)($this->bob))->toBe([$this->lamp['variantId']]);
    });

    it('refuses what cannot be saved, with a reason', function () {
        $draft = ($this->make)('Draft', 'খসড়া', 'DRAFT-1', 100, 5, publish: false);
        $archived = ($this->make)('Gone', 'শেষ', 'GONE-1', 100, 5);
        app(TransitionProduct::class)->archive($this->actor, $archived['product']);

        ($this->save)($this->service)->assertSessionHasErrors('wishlist');
        ($this->save)($draft)->assertSessionHasErrors('wishlist');
        ($this->save)($archived)->assertSessionHasErrors('wishlist');
        $this->actingAs($this->amina, 'web')->post('/en/account/wishlist', ['variant' => str_repeat('0', 26)])->assertSessionHasErrors('wishlist');
        $this->actingAs($this->amina, 'web')->post('/en/account/wishlist', ['variant' => '1'])->assertSessionHasErrors('variant');
        $this->actingAs($this->amina, 'web')->post('/en/account/wishlist', [])->assertSessionHasErrors('variant');

        expect(($this->saved)())->toBe([]);
    });

    it('stops at the most items a list may hold', function () {
        config(['engagement.wishlist_max' => 2]);
        $third = ($this->make)('Third', 'তৃতীয়', 'THIRD-1', 100, 5);

        ($this->save)($this->tee);
        ($this->save)($this->lamp);
        ($this->save)($third)->assertSessionHasErrors('wishlist');
        ($this->save)($this->tee)->assertSessionHasNoErrors();   // what is already there is not "one more"

        expect(($this->saved)())->toBe([$this->tee['variantId'], $this->lamp['variantId']]);
        expect(fn () => app(AddToWishlist::class)->handle($this->amina->id, $third['publicId'], 'en'))->toThrow(EngagementException::class);
    });

    it('is throttled like other writes', function () {
        foreach (range(1, 60) as $i) {
            ($this->save)($this->tee);
        }

        ($this->save)($this->tee)->assertStatus(429);
    });
});

describe('the wishlist page', function () {
    it('lists what was saved, newest first, with today\'s price and stock', function () {
        ($this->save)($this->tee);
        ($this->save)($this->lamp);
        ($this->setPrice)($this->tee, 700);
        ($this->setStock)($this->lamp, 0);

        $html = $this->actingAs($this->amina, 'web')->get('/en/account/wishlist')->assertOk()->getContent();
        expect(strpos($html, 'Brass Lamp'))->toBeLessThan(strpos($html, 'Cotton Tee'))
            ->and($html)->toContain('700.00')->and($html)->toContain('Out of stock')->and($html)->toContain('In stock')->and($html)->toContain('Move to cart');
    });

    it('shows only the customer\'s own list', function () {
        ($this->save)($this->tee, $this->bob);

        $this->actingAs($this->amina, 'web')->get('/en/account/wishlist')->assertOk()->assertDontSee('Cotton Tee')->assertSee('You have not saved anything yet');
    });

    it('reads a long list in the same number of queries as a short one', function () {
        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->amina, 'web')->get('/en/account/wishlist')->assertOk();
            $log = array_map(fn ($q) => $q['query'], DB::getQueryLog());
            DB::disableQueryLog();
            $this->lastQueries = $log;

            return count($log);
        };
        ($this->save)($this->tee);
        ($this->save)($this->lamp);
        ($this->setStock)($this->lamp, 0);
        $queries();   // warm
        $few = $queries();
        foreach (range(1, 8) as $i) {
            ($this->save)(($this->make)("Item {$i}", "পণ্য {$i}", "ITEM-{$i}", 100 + $i, 5));
        }

        $queries();   // a new product also refreshes what the page caches; measure the second look
        $many = $queries();
        expect($many)->toBe($few);
    });

    it('is shown in English and in Bangla with no missing words', function () {
        ($this->save)($this->tee);

        foreach (['en' => 'My wishlist', 'bn' => 'আমার উইশলিস্ট'] as $locale => $title) {
            $response = $this->actingAs($this->amina, 'web')->get("/{$locale}/account/wishlist")->assertOk()->assertSee($title);
            expect($response->getContent())->not->toContain('engagement::');
        }
    });
});

describe('taking items off and moving them to the cart', function () {
    it('removes only the customer\'s own entry', function () {
        ($this->save)($this->tee);
        ($this->save)($this->tee, $this->bob);

        $this->actingAs($this->amina, 'web')->delete('/en/account/wishlist/'.$this->tee['publicId'])->assertSessionHas('status');

        expect(($this->saved)())->toBe([])->and(($this->saved)($this->bob))->toBe([$this->tee['variantId']]);
    });

    it('moves an item to the cart at today\'s price, and it leaves the list', function () {
        ($this->save)($this->tee);
        ($this->setPrice)($this->tee, 700);

        $this->actingAs($this->amina, 'web')->post('/en/account/wishlist/'.$this->tee['publicId'].'/cart')->assertRedirect('/en/cart')->assertSessionHas('status');

        expect(($this->saved)())->toBe([])
            ->and(CartItem::query()->whereIn('cart_id', Cart::query()->where('user_id', $this->amina->id)->select('id'))->get()->map(fn ($i) => [(int) $i->variant_id, (int) $i->quantity, (int) $i->added_price_minor])->all())->toBe([[$this->tee['variantId'], 1, 70000]]);
    });

    it('moves the smallest order an item is sold in', function () {
        ($this->save)($this->lamp);   // a lamp is sold in twos, from 2

        $this->actingAs($this->amina, 'web')->post('/en/account/wishlist/'.$this->lamp['publicId'].'/cart');

        expect((int) CartItem::query()->where('variant_id', $this->lamp['variantId'])->value('quantity'))->toBe(2);
    });

    it('keeps an item on the list when it cannot go in the cart, and says why', function () {
        ($this->save)($this->tee);
        ($this->setStock)($this->tee, 0);

        $this->actingAs($this->amina, 'web')->post('/en/account/wishlist/'.$this->tee['publicId'].'/cart')->assertSessionHasErrors('wishlist');

        expect(($this->saved)())->toBe([$this->tee['variantId']])->and(CartItem::query()->count())->toBe(0);
    });

    it('cannot move what is not on the customer\'s list, even if it is on someone else\'s', function () {
        ($this->save)($this->tee, $this->bob);

        $this->actingAs($this->amina, 'web')->post('/en/account/wishlist/'.$this->tee['publicId'].'/cart')->assertSessionHasErrors('wishlist');

        expect(CartItem::query()->count())->toBe(0)->and(($this->saved)($this->bob))->toBe([$this->tee['variantId']]);
    });
});

describe('the product page', function () {
    it('invites a guest to sign in, and a member to save', function () {
        $this->get('/en/p/'.$this->tee['slug'])->assertOk()->assertSee('Sign in to save for later')->assertDontSee('Save for later</button>', false);

        $page = $this->actingAs($this->amina, 'web')->get('/en/p/'.$this->tee['slug'])->assertOk();
        $page->assertSee('Save for later');
        ($this->save)($this->tee);
        $this->actingAs($this->amina, 'web')->get('/en/p/'.$this->tee['slug'])->assertSee('Saved for later');
    });

    it('offers no saving for a service, which is booked', function () {
        $this->actingAs($this->amina, 'web')->get('/en/p/'.$this->service['slug'])->assertOk()->assertDontSee('Save for later');
    });
});
