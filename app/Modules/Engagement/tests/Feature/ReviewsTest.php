<?php

declare(strict_types=1);

use App\Modules\Customers\Contracts\CustomerProfiles;
use App\Modules\Engagement\Actions\ModerateReview;
use App\Modules\Engagement\Actions\SubmitReview;
use App\Modules\Engagement\Exceptions\EngagementException;
use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function () {
    shopWorld($this);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    $this->user = function (string $name, string $email): User {
        $user = User::factory()->create(['name' => $name, 'email' => $email]);
        app(CustomerProfiles::class)->ensureForUser($user->id, $name, $email, null);

        return $user;
    };
    $this->amina = ($this->user)('Amina Rahman', 'amina@example.com');
    $this->bob = ($this->user)('Bob Karim', 'bob@example.com');
    $this->done = ($this->orderFor)($this->amina->id, [$this->tee['variantId'] => 1, $this->ebook['variantId'] => 1]);
    ($this->completeOrder)($this->done->id);
    $this->url = fn ($order, array $v) => "/en/account/orders/{$order->public_id}/reviews/{$v['publicId']}";
    $this->write = fn (array $over = [], ?User $as = null, $order = null, ?array $v = null, string $locale = 'en') => $this->actingAs($as ?? $this->amina, 'web')
        ->post(str_replace('/en/', "/{$locale}/", ($this->url)($order ?? $this->done, $v ?? $this->tee)), $over + ['rating' => '5', 'title' => 'Lovely', 'body' => 'Soft cotton and a good fit, I would buy it again.']);
    $this->reviews = fn () => DB::table('reviews')->orderBy('id')->get();
});

describe('who may review', function () {
    it('is for signed-in customers', function () {
        $this->get(($this->url)($this->done, $this->tee))->assertRedirect('/en/login');
        $this->post(($this->url)($this->done, $this->tee), ['rating' => 5, 'body' => 'Soft cotton and a good fit.'])->assertRedirect('/en/login');
    });

    it('shows the form for something in a completed, paid order', function () {
        $this->actingAs($this->amina, 'web')->get(($this->url)($this->done, $this->tee))->assertOk()->assertSee('Write a review')->assertSee('Cotton Tee')->assertSee('We read every review');
    });

    it('is "not found" for someone else\'s order, and nothing is saved', function () {
        $this->actingAs($this->bob, 'web')->get(($this->url)($this->done, $this->tee))->assertNotFound();
        ($this->write)([], $this->bob)->assertNotFound();

        expect(($this->reviews)())->toHaveCount(0);
    });

    it('is refused for an item that is not in the order', function () {
        $this->actingAs($this->amina, 'web')->get(($this->url)($this->done, $this->lamp))->assertRedirect("/en/account/orders/{$this->done->public_id}")->assertSessionHasErrors('review');
        ($this->write)([], null, null, $this->lamp)->assertNotFound();   // not a line of that order

        expect(($this->reviews)())->toHaveCount(0);
    });

    it('is refused until the order is complete and paid, but allowed for a digital item once paid', function () {
        $pending = ($this->orderFor)($this->amina->id, [$this->tee['variantId'] => 1, $this->ebook['variantId'] => 1]);
        ($this->write)([], null, $pending)->assertSessionHasErrors('review');                       // not even confirmed
        ($this->write)([], null, $pending, $this->ebook)->assertSessionHasErrors('review');

        ($this->payOrder)($pending->id);                                                              // paid, but the parcel has not arrived
        ($this->write)([], null, $pending)->assertSessionHasErrors('review');
        ($this->write)([], null, $pending, $this->ebook)->assertSessionHasNoErrors()->assertRedirect('/en/account/reviews');   // a download is there as soon as it is paid

        expect(($this->reviews)())->toHaveCount(1);
    });

    it('is refused for a cancelled or refunded order', function () {
        $cancelled = ($this->orderFor)($this->amina->id, [$this->tee['variantId'] => 1]);
        app(TransitionOrder::class)->status(Actor::system('t'), $cancelled->id, OrderStatus::Cancelled, 'test');
        $refunded = ($this->orderFor)($this->amina->id, [$this->ebook['variantId'] => 1]);
        ($this->payOrder)($refunded->id);
        DB::table('orders')->where('id', $refunded->id)->update(['payment_status' => 'refunded']);

        $paidThenCancelled = ($this->orderFor)($this->amina->id, [$this->ebook['variantId'] => 1]);   // a download that was paid for, then marked cancelled
        ($this->payOrder)($paidThenCancelled->id);
        // The shop refuses to cancel a paid order before it is refunded, so this state only arises from a data correction; the review rule must still hold.
        DB::table('orders')->where('id', $paidThenCancelled->id)->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => 'corrected']);
        expect(DB::table('orders')->where('id', $paidThenCancelled->id)->value('payment_status'))->toBe('paid');
        ($this->write)([], null, $paidThenCancelled, $this->ebook)->assertSessionHasErrors('review');
        ($this->write)([], null, $cancelled)->assertSessionHasErrors('review');
        ($this->write)([], null, $refunded, $this->ebook)->assertSessionHasErrors('review');

        expect(($this->reviews)())->toHaveCount(0);
    });

    it('stops after the window, and not before', function () {
        $this->travel(179)->days();
        ($this->write)()->assertSessionHasNoErrors();
        $this->travel(2)->days();
        ($this->write)(['body' => 'A different and honest review of the e-book, written late.'], null, null, $this->ebook)->assertSessionHasErrors('review');   // only the time limit can refuse this one

        expect(($this->reviews)())->toHaveCount(1);
    });
});

describe('writing a review', function () {
    it('is saved waiting for staff, with the first name and last initial, not shown to anyone yet', function () {
        ($this->write)()->assertRedirect('/en/account/reviews')->assertSessionHas('status');

        $r = ($this->reviews)()->first();
        expect($r->status)->toBe('pending')->and($r->rating)->toBe(5)->and($r->title)->toBe('Lovely')->and($r->author_name)->toBe('Amina R.')->and($r->locale)->toBe('en')->and($r->moderated_by)->toBeNull()
            ->and((int) $r->variant_id)->toBe($this->tee['variantId'])->and((int) $r->product_id)->toBe((int) $this->tee['product']->id);
        $this->get('/en/p/'.$this->tee['slug'])->assertOk()->assertDontSee('Soft cotton')->assertDontSee('Customer reviews');
        expect(DB::table('product_rating_stats')->count())->toBe(0);
    });

    it('notes the language it was written in, from the words themselves', function () {
        ($this->write)(['body' => 'কাপড়টা খুব নরম এবং মাপও একদম ঠিক।', 'title' => 'চমৎকার'])->assertSessionHasNoErrors();
        ($this->write)(['body' => 'Soft cotton and a good fit, buy it.'], null, null, $this->ebook)->assertSessionHasNoErrors();

        expect(($this->reviews)()->pluck('locale')->all())->toBe(['bn', 'en']);
    });

    it('is one review per item, however many times it is sent', function () {
        ($this->write)()->assertSessionHasNoErrors();
        ($this->write)(['body' => 'A completely different second review text.'])->assertSessionHasErrors('review');
        ($this->write)()->assertSessionHasErrors('review');

        expect(($this->reviews)())->toHaveCount(1);
        expect(fn () => DB::table('reviews')->insert(['public_id' => strtolower((string) Str::ulid()), 'product_id' => ($this->reviews)()->first()->product_id, 'variant_id' => $this->tee['variantId'], 'order_item_id' => ($this->reviews)()->first()->order_item_id,
            'user_id' => $this->amina->id, 'rating' => 3, 'body' => 'another one', 'locale' => 'en', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]))->toThrow(UniqueConstraintViolationException::class);
    });

    it('refuses a bad rating, text that is too short or too long, and links', function () {
        $this->withoutMiddleware(ThrottleRequests::class);   // the hourly limit has its own test
        foreach ([['rating' => '0'], ['rating' => '6'], ['rating' => ''], ['rating' => 'abc'], ['body' => 'short'], ['body' => str_repeat('x', 2001)], ['title' => str_repeat('t', 101)],
            ['body' => 'Great shirt, see https://example.com for more'], ['body' => 'Great shirt, see www.example.org for more'], ['body' => 'Great shirt, buy cheaper at deals.com today'], ['title' => 'Visit shop.xyz']] as $bad) {
            ($this->write)($bad)->assertSessionHasErrors();
        }

        expect(($this->reviews)())->toHaveCount(0);
    });

    it('accepts a Bangla digit for the rating, and removes control characters', function () {
        ($this->write)(['rating' => '৪', 'body' => "Soft cotton\x00 and a good fit.\x07"])->assertSessionHasNoErrors();

        $r = ($this->reviews)()->first();
        expect($r->rating)->toBe(4)->and($r->body)->toBe('Soft cotton and a good fit.');
    });

    it('does not accept the same words twice within a month, but does after', function () {
        ($this->write)()->assertSessionHasNoErrors();
        ($this->write)([], null, null, $this->ebook)->assertSessionHasErrors('review');   // identical text on another item

        $this->travel(31)->days();
        ($this->write)([], null, null, $this->ebook)->assertSessionHasNoErrors();
        expect(($this->reviews)())->toHaveCount(2);
    });

    it('limits how many a customer can send in an hour', function () {
        expect(collect(Route::getRoutes()->getByName('account.reviews.store')->gatherMiddleware())->contains('throttle:reviews'))->toBeTrue();
        $limit = RateLimiter::limiter('reviews')(request());
        expect($limit->maxAttempts)->toBe(5)->and($limit->decaySeconds)->toBe(3600);
    });

    it('keeps the words as text: nothing typed is run as a page', function () {
        ($this->write)(['title' => '<script>alert(1)</script>', 'body' => '<b>bold</b> <img src=x onerror=alert(1)> cotton and a good fit'])->assertSessionHasNoErrors();
        $mod = catalogStaff(['reviews.moderate']);
        app(ModerateReview::class)->approve(new Actor(ActorType::Staff, $mod->id, null, 'S'), ($this->reviews)()->first()->public_id);

        $html = $this->get('/en/p/'.$this->tee['slug'])->getContent();
        expect($html)->not->toContain('<script>alert(1)')->and($html)->not->toContain('<img src=x')->and($html)->toContain('&lt;script&gt;');
    });
});

describe('the customer\'s own reviews', function () {
    it('lists only their own, with the state and the reason when it was refused', function () {
        ($this->write)()->assertSessionHasNoErrors();
        $bobs = ($this->orderFor)($this->bob->id, [$this->ebook['variantId'] => 1]);
        ($this->payOrder)($bobs->id);
        ($this->write)(['body' => 'Bob liked the e-book a lot, clear recipes.'], $this->bob, $bobs, $this->ebook);
        DB::table('reviews')->where('user_id', $this->amina->id)->update(['status' => 'rejected', 'rejection_reason' => 'Please talk about the item itself.']);

        $this->actingAs($this->amina, 'web')->get('/en/account/reviews')->assertOk()->assertSee('Soft cotton')->assertSee('Not published')->assertSee('Please talk about the item itself.')->assertDontSee('Bob liked');
    });

    it('can be withdrawn by the author only', function () {
        ($this->write)()->assertSessionHasNoErrors();
        $id = ($this->reviews)()->first()->public_id;

        $this->actingAs($this->bob, 'web')->post("/en/account/reviews/{$id}/withdraw")->assertNotFound();
        expect(($this->reviews)()->first()->status)->toBe('pending');

        $this->actingAs($this->amina, 'web')->post("/en/account/reviews/{$id}/withdraw")->assertSessionHas('status');
        $this->actingAs($this->amina, 'web')->post("/en/account/reviews/{$id}/withdraw")->assertSessionHasErrors('review');   // already withdrawn
        expect(($this->reviews)()->first()->status)->toBe('withdrawn');
    });

    it('is offered on the order page, and shown as done once written', function () {
        $this->actingAs($this->amina, 'web')->get("/en/account/orders/{$this->done->public_id}")->assertOk()->assertSee('Review what you bought')->assertSee('Write a review');
        ($this->write)();

        $this->actingAs($this->amina, 'web')->get("/en/account/orders/{$this->done->public_id}")->assertSee('Reviewed');
        $pending = ($this->orderFor)($this->amina->id, [$this->tee['variantId'] => 1]);
        $this->actingAs($this->amina, 'web')->get("/en/account/orders/{$pending->public_id}")->assertDontSee('Review what you bought');
    });

    it('is shown in English and in Bangla with no missing words', function () {
        foreach (['en', 'bn'] as $locale) {
            foreach (["/{$locale}/account/orders/{$this->done->public_id}/reviews/{$this->tee['publicId']}", "/{$locale}/account/reviews", "/{$locale}/account/orders/{$this->done->public_id}"] as $page) {
                expect($this->actingAs($this->amina, 'web')->get($page)->assertOk()->getContent())->not->toContain('engagement::');
            }
        }
        $this->actingAs($this->amina, 'web')->get("/bn/account/orders/{$this->done->public_id}/reviews/{$this->tee['publicId']}")->assertSee('রিভিউ লিখুন');
    });

    it('has the same words in English and Bangla in every file', function () {
        $keys = function (array $a, string $prefix = '') use (&$keys): array {
            $out = [];
            foreach ($a as $k => $v) {
                is_array($v) ? array_push($out, ...$keys($v, $prefix.$k.'.')) : $out[] = $prefix.$k;
            }

            return $out;
        };
        foreach (['reviews', 'errors', 'wishlist'] as $file) {
            $en = $keys(require app_path("Modules/Engagement/resources/lang/en/{$file}.php"));
            $bn = $keys(require app_path("Modules/Engagement/resources/lang/bn/{$file}.php"));
            expect(array_values(array_diff($en, $bn)))->toBe([])->and(array_values(array_diff($bn, $en)))->toBe([]);
        }
    });

    it('rejects text called directly that the form would have refused', function () {
        expect(fn () => app(SubmitReview::class)->handle($this->amina->id, $this->done->public_id, $this->tee['publicId'], 5, null, 'see http://x.example.com'))->toThrow(EngagementException::class)
            ->and(fn () => app(SubmitReview::class)->handle($this->amina->id, $this->done->public_id, $this->tee['publicId'], 9, null, 'a perfectly fine long review'))->toThrow(EngagementException::class)
            ->and(fn () => app(SubmitReview::class)->handle($this->amina->id, $this->done->public_id, $this->tee['publicId'], 5, null, 'too short'))->toThrow(EngagementException::class)
            ->and(fn () => app(SubmitReview::class)->handle($this->amina->id, $this->done->public_id, $this->tee['publicId'], 5, null, str_repeat('y', 2001)))->toThrow(EngagementException::class);
    });
});

it('shortens a name to the first name and the last initial, and copes with no name', function () {
    expect(SubmitReview::shortName('Amina Rahman'))->toBe('Amina R.')->and(SubmitReview::shortName('  Md.  Abdul   Karim Chowdhury '))->toBe('Md. C.')->and(SubmitReview::shortName('Amina'))->toBe('Amina')
        ->and(SubmitReview::shortName('আমিনা রহমান'))->toBe('আমিনা র.')->and(SubmitReview::shortName(null))->toBeNull()->and(SubmitReview::shortName('   '))->toBeNull();
});
