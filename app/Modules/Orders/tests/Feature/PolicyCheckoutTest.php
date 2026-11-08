<?php

declare(strict_types=1);

use App\Modules\Cart\Actions\AddToCart;
use App\Modules\Cart\DTOs\CartOwner;
use App\Modules\Cart\Services\GuestCartToken;
use App\Modules\Catalog\Actions\SaveCategory;
use App\Modules\Catalog\Actions\SaveProduct;
use App\Modules\Catalog\Actions\TransitionProduct;
use App\Modules\Catalog\DTOs\ProductData;
use App\Modules\Catalog\DTOs\TranslationInput;
use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Cms\Actions\AssignPolicyKind;
use App\Modules\Cms\Actions\SavePage;
use App\Modules\Cms\Actions\TransitionPage;
use App\Modules\Cms\Contracts\PolicyAcceptances;
use App\Modules\Cms\Enums\PolicyKind;
use App\Modules\Cms\Models\Page;
use App\Modules\Customers\Database\Seeders\GeographySeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Inventory\Database\Seeders\StockLocationSeeder;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Database\Seeders\PaymentMethodSeeder;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Pricing\Actions\SetRegularPrice;
use App\Modules\Pricing\Database\Seeders\PriceListSeeder;
use App\Modules\Pricing\Database\Seeders\TaxClassSeeder;
use App\Modules\Shipping\Database\Seeders\ShippingSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

/*
 * Phase 10, slice 3b at checkout: the "I accept" box on the review page, what is recorded with the order, and what happens when a policy changes mid-checkout.
 */

beforeEach(function () {
    Notification::fake();
    $this->seed([LanguageSeeder::class, TaxClassSeeder::class, PriceListSeeder::class, StockLocationSeeder::class, GeographySeeder::class, ShippingSeeder::class, PaymentMethodSeeder::class]);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    $this->actor = Actor::system('t');
    $category = app(SaveCategory::class)->handle($this->actor, null, null, [new TranslationInput('en', 'General')]);

    $product = app(SaveProduct::class)->handle($this->actor, null, new ProductData(ProductType::Physical, [new TranslationInput('en', 'Cotton Tee'), new TranslationInput('bn', 'সুতির টি-শার্ট')], categoryIds: [$category->id],
        defaultVariant: new VariantData('TEE-1', trackInventory: true, weightG: 300)));
    $this->variant = (int) $product->variants()->value('id');
    app(SetRegularPrice::class)->handle($this->actor, $this->variant, Money::of(65000));
    DB::table('stock_levels')->insert(['variant_id' => $this->variant, 'location_id' => (int) DB::table('stock_locations')->value('id'), 'on_hand' => 100, 'reserved' => 0, 'updated_at' => now()]);
    app(TransitionProduct::class)->publish($this->actor, $product);

    $this->dhaka = (int) DB::table('districts')->where('name_en', 'Dhaka')->value('id');
    $this->division = (int) DB::table('districts')->where('id', $this->dhaka)->value('division_id');
    $this->upazila = (int) DB::table('upazilas')->where('district_id', $this->dhaka)->value('id');
    $this->token = str_repeat('f', 40);
    $this->cart = fn () => app(AddToCart::class)->handle(CartOwner::guest($this->token), $this->variant, 1);
    $this->as = fn () => $this->withCookie(GuestCartToken::COOKIE, $this->token);
    $this->details = [
        'recipient' => 'Rahim Uddin', 'phone' => '01712345678', 'division_id' => $this->division, 'district_id' => $this->dhaka, 'upazila_id' => $this->upazila,
        'address_line' => 'House 5, Road 2', 'area' => 'Dhanmondi', 'postal_code' => '1205', 'email' => 'rahim@example.com',
    ];

    /** Publishes (or re-publishes with new text) the policy of a kind. */
    $this->release = function (string $kind, ?string $title = null) {
        $page = Page::query()->where('kind', $kind)->first();
        $title ??= ucfirst($kind);
        if ($page === null) {
            $page = app(SavePage::class)->handle($this->actor, null, ['en' => ['title' => $title, 'slug' => $kind, 'body' => '<p>text</p>']]);
            app(AssignPolicyKind::class)->handle($this->actor, $page, PolicyKind::from($kind));
            $number = 1;
        } else {
            $base = (int) $page->draftVersion->number;
            $page = app(SavePage::class)->handle($this->actor, $page, ['en' => ['title' => $title, 'slug' => $kind, 'body' => '<p>text</p>']], $base);
            $number = $base + 1;
        }
        app(TransitionPage::class)->publish($this->actor, $page, $number);
    };
    $this->releaseIds = fn (?int $userId = null, string $pay = 'cod') => array_map(fn ($n) => $n->releaseId, app(PolicyAcceptances::class)->outstanding($userId, 'checkout', $pay, 'en'));

    /** Walks to the review page and returns what the page says: token, total and the policy ids in its box. */
    $this->review = function (?User $as = null, array $form = []) {
        $as === null ? ($this->cart)() : app(AddToCart::class)->handle(CartOwner::user($as->id), $this->variant, 1);   // a signed-in shopper has their own cart
        $call = fn () => $as === null ? ($this->as)() : $this->actingAs($as, 'web');
        $details = $as === null ? $this->details : ['saved_address' => null] + $this->details;
        $call()->post('/en/checkout', array_replace($details, $form))->assertRedirect('/en/checkout/review');
        $html = $call()->get('/en/checkout/review')->assertOk()->getContent();
        preg_match('/name="place_token" value="([^"]+)"/', $html, $t);
        preg_match('/name="expected_total" value="(\d+)"/', $html, $e);
        preg_match_all('/name="policy_releases\[\]" value="(\d+)"/', $html, $p);

        return ['token' => $t[1], 'total' => $e[1], 'ids' => array_map('intval', $p[1]), 'html' => $html];
    };
    $this->place = function (array $page, array $over = [], ?User $as = null) {
        $call = $as === null ? ($this->as)() : $this->actingAs($as, 'web');

        return $call->post('/en/checkout/place', array_replace(['place_token' => $page['token'], 'expected_total' => $page['total'], 'shipping' => 'standard', 'payment' => 'cod'], $over));
    };
    $this->customer = fn () => User::factory()->verified()->create(['email' => 'k@example.com']);
});

describe('on the review page', function () {
    it('shows no box while nothing is published, and places the order as before', function () {
        $page = ($this->review)();

        expect($page['ids'])->toBe([])->and($page['html'])->not->toContain('accept_policies');
        ($this->place)($page)->assertRedirect();
        expect(Order::count())->toBe(1)->and(DB::table('policy_acceptances')->count())->toBe(0);
    });

    it('shows the box with the policies, in both languages', function () {
        ($this->release)('terms', 'Terms');
        ($this->release)('privacy', 'Privacy');
        $page = ($this->review)();

        expect($page['ids'])->toHaveCount(2)->and($page['html'])->toContain('name="accept_policies"')->toContain('I have read and accept the')->toContain(url('/en/pages/terms'));
        ($this->as)()->get('/bn/checkout/review')->assertOk()->assertSee('আমি পড়েছি এবং মেনে নিচ্ছি:');
    });
});

describe('placing the order', function () {
    it('refuses an order without the box ticked and writes nothing', function () {
        ($this->release)('terms');
        ($this->release)('privacy');
        $page = ($this->review)();

        ($this->place)($page)->assertRedirect();                                             // nothing ticked
        ($this->place)($page, ['policy_releases' => $page['ids']])->assertRedirect();        // ids sent, box not ticked
        ($this->place)($page, ['accept_policies' => '1'])->assertRedirect();                 // ticked, but the page's ids are missing

        expect(Order::count())->toBe(0)->and(DB::table('policy_acceptances')->count())->toBe(0)->and((int) DB::table('stock_levels')->sum('reserved'))->toBe(0);
        ($this->as)()->get('/en/checkout/review')->assertOk()->assertSee('name="accept_policies"', false);
    });

    it('records a guest\'s acceptance against the order, in the order\'s own transaction', function () {
        ($this->release)('terms');
        ($this->release)('privacy');
        $page = ($this->review)();

        ($this->place)($page, ['accept_policies' => '1', 'policy_releases' => $page['ids']])->assertRedirect();

        $order = Order::query()->firstOrFail();
        expect(DB::table('policy_acceptances')->where('order_id', $order->id)->where('channel', 'checkout')->whereNull('user_id')->count())->toBe(2)
            ->and(DB::table('policy_acceptances')->whereNull('order_id')->count())->toBe(0);
    });

    it('refuses an order when a policy changed while the shopper was reading, and takes the new one next time', function () {
        ($this->release)('terms');
        ($this->release)('privacy');
        $page = ($this->review)();
        ($this->release)('terms', 'Terms changed');

        ($this->place)($page, ['accept_policies' => '1', 'policy_releases' => $page['ids']])->assertRedirect();
        expect(Order::count())->toBe(0)->and(DB::table('policy_acceptances')->count())->toBe(0);

        $fresh = ($this->review)();
        expect($fresh['ids'])->not->toBe($page['ids']);
        ($this->place)($fresh, ['accept_policies' => '1', 'policy_releases' => $fresh['ids']])->assertRedirect();
        expect(Order::count())->toBe(1)->and(DB::table('policy_acceptances')->count())->toBe(2);
    });

    it('asks for the cash-on-delivery policy too when paying cash, and records all three', function () {
        ($this->release)('terms');
        ($this->release)('privacy');
        ($this->release)('cod', 'COD terms');
        $page = ($this->review)();

        expect($page['ids'])->toHaveCount(3)->and($page['html'])->toContain('COD terms');
        ($this->place)($page, ['accept_policies' => '1', 'policy_releases' => array_slice($page['ids'], 0, 2)])->assertRedirect();   // the cash policy left out
        expect(Order::count())->toBe(0);

        ($this->place)($page, ['accept_policies' => '1', 'policy_releases' => $page['ids']])->assertRedirect();
        expect(DB::table('policy_acceptances')->where('order_id', Order::query()->value('id'))->count())->toBe(3);
    });

    it('records once when the same checkout is submitted twice', function () {
        ($this->release)('terms');
        $page = ($this->review)();
        $body = ['accept_policies' => '1', 'policy_releases' => $page['ids']];

        ($this->place)($page, $body)->assertRedirect();
        ($this->place)($page, $body)->assertRedirect();

        expect(Order::count())->toBe(1)->and(DB::table('policy_acceptances')->count())->toBe(1);
    });

    it('records nothing when the order itself is refused for another reason', function () {
        ($this->release)('terms');
        $page = ($this->review)();

        ($this->place)($page, ['accept_policies' => '1', 'policy_releases' => $page['ids'], 'expected_total' => '1'])->assertRedirect();   // the total is not what the page showed

        expect(Order::count())->toBe(0)->and(DB::table('policy_acceptances')->count())->toBe(0);
    });
});

describe('a signed-in customer', function () {
    it('accepts for themselves, is not asked again, and is asked again after a required change', function () {
        ($this->release)('terms');
        $u = ($this->customer)();
        $page = ($this->review)($u, ['email' => 'k@example.com']);

        expect($page['ids'])->toHaveCount(1);
        ($this->place)($page, ['accept_policies' => '1', 'policy_releases' => $page['ids']], $u)->assertRedirect();
        expect(DB::table('policy_acceptances')->where('user_id', $u->id)->where('channel', 'checkout')->count())->toBe(1)
            ->and(DB::table('policy_acceptances')->whereNotNull('order_id')->count())->toBe(0);   // a customer's belongs to the customer

        $again = ($this->review)($u, ['email' => 'k@example.com']);                              // nothing outstanding any more
        expect($again['ids'])->toBe([])->and($again['html'])->not->toContain('accept_policies');
        ($this->place)($again, [], $u)->assertRedirect();
        expect(Order::count())->toBe(2)->and(DB::table('policy_acceptances')->count())->toBe(1);

        ($this->release)('terms', 'Terms big change');
        $third = ($this->review)($u, ['email' => 'k@example.com']);
        expect($third['ids'])->toHaveCount(1);
        ($this->place)($third, [], $u)->assertRedirect();
        expect(Order::count())->toBe(2);                                                          // refused until they tick the box
    });

    it('is not blocked from the cart or their orders while something is outstanding', function () {
        ($this->release)('terms');
        $u = ($this->customer)();

        $this->actingAs($u, 'web')->get('/en/cart')->assertOk();
        $this->actingAs($u, 'web')->get('/en/account/orders')->assertOk();
    });
});

describe('making an account from a guest order', function () {
    it('carries over what the guest accepted at checkout', function () {
        ($this->release)('terms');
        ($this->release)('privacy');
        $page = ($this->review)();
        ($this->place)($page, ['accept_policies' => '1', 'policy_releases' => $page['ids']])->assertRedirect();
        $order = Order::query()->firstOrFail();

        $url = URL::temporarySignedRoute('checkout.account.create', now()->addHour(), ['locale' => 'en', 'order' => $order->public_id]);
        ($this->as)()->post($url, ['name' => 'Rahim Uddin', 'email' => 'new@example.com', 'password' => 'Tr0ub4dor-and-3-horses', 'password_confirmation' => 'Tr0ub4dor-and-3-horses'])->assertRedirect();

        $user = User::query()->where('email', 'new@example.com')->firstOrFail();
        expect(DB::table('policy_acceptances')->where('user_id', $user->id)->count())->toBe(2)
            ->and(app(PolicyAcceptances::class)->outstanding($user->id, 'signup', null, 'en'))->toBe([])
            ->and(DB::table('policy_acceptances')->where('order_id', $order->id)->count())->toBe(2);
    });
});
