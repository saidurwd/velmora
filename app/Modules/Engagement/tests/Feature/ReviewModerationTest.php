<?php

declare(strict_types=1);

use App\Modules\Customers\Contracts\CustomerProfiles;
use App\Modules\Engagement\Actions\ModerateReview;
use App\Modules\Engagement\Actions\SubmitReview;
use App\Modules\Engagement\Actions\WithdrawReview;
use App\Modules\Engagement\Privacy\EngagementPersonalData;
use App\Modules\Engagement\Services\RatingStats;
use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    shopWorld($this);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    $this->moderator = catalogStaff(['reviews.moderate']);
    $this->nobody = catalogStaff(['catalog.view']);
    $this->staff = new Actor(ActorType::Staff, $this->moderator->id, $this->moderator->public_id, 'Moderator');
    $this->customers = 0;
    /** A customer with a paid e-book order; returns [user, order]. */
    $this->buyer = function (string $name = 'Amina Rahman') {
        $this->customers++;
        $user = User::factory()->create(['name' => $name, 'email' => "c{$this->customers}@example.com"]);
        app(CustomerProfiles::class)->ensureForUser($user->id, $name, $user->email, null);
        $order = ($this->orderFor)($user->id, [$this->ebook['variantId'] => 1]);
        ($this->payOrder)($order->id);

        return [$user, $order];
    };
    /** A review waiting for staff, by a new customer; returns its public id. */
    $this->review = function (int $rating = 5, ?array $v = null, ?string $text = null) {
        [$user, $order] = ($this->buyer)();

        return app(SubmitReview::class)->handle($user->id, $order->public_id, ($v ?? $this->ebook)['publicId'], $rating, 'Title', $text ?? 'Review number '.uniqid().' of the e-book, very clear.');
    };
    $this->stats = fn () => app(RatingStats::class)->forProduct((int) $this->ebook['product']->id);
    $this->status = fn (string $id) => DB::table('reviews')->where('public_id', $id)->value('status');
    $this->recount = function (): array {
        $stars = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        foreach (DB::table('reviews')->where('product_id', $this->ebook['product']->id)->where('status', 'published')->pluck('rating') as $rating) {
            $stars[(int) $rating]++;
        }

        return $stars;
    };
});

describe('who may moderate', function () {
    it('is only for staff with the permission', function () {
        $id = ($this->review)();

        $this->get('/admin/reviews')->assertRedirect();
        $this->actingAs(User::factory()->create(), 'web')->get('/admin/reviews')->assertRedirect();   // a customer's sign-in is not a staff sign-in
        $this->actingAs($this->nobody, 'admin')->get('/admin/reviews')->assertForbidden();
        $this->actingAs($this->nobody, 'admin')->post("/admin/reviews/{$id}/approve")->assertForbidden();
        $this->actingAs($this->nobody, 'admin')->post("/admin/reviews/{$id}/reject", ['reason' => 'x'])->assertForbidden();

        expect(($this->status)($id))->toBe('pending');
    });

    it('shows the queue with the oldest first, what was written, and who wrote it', function () {
        ($this->review)(5, null, 'First review text, nicely written.');
        ($this->review)(2, null, 'Second review text, not so happy.');

        $this->actingAs($this->moderator, 'admin')->get('/admin/reviews')->assertOk()->assertSee('Review moderation')->assertSeeInOrder(['First review text', 'Second review text'])->assertSee('Amina R.')->assertSee('Cooking e-book');
    });

    it('lists the queue in a fixed number of queries as it grows', function () {
        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->moderator, 'admin')->get('/admin/reviews')->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        ($this->review)();
        $queries();
        $few = $queries();
        foreach (range(1, 6) as $i) {
            ($this->review)();
        }
        $queries();

        expect($queries())->toBe($few);
    });

    it('is shown to staff in English and in Bangla with no missing words', function () {
        ($this->review)();
        $this->moderator->forceFill(['locale' => 'bn'])->save();

        expect($this->actingAs($this->moderator, 'admin')->get('/admin/reviews')->assertOk()->assertSee('রিভিউ যাচাই')->getContent())->not->toContain('engagement::');
    });
});

describe('deciding', function () {
    it('publishes a review and counts its stars, once, and records who decided', function () {
        $id = ($this->review)(4);

        $this->actingAs($this->moderator, 'admin')->post("/admin/reviews/{$id}/approve")->assertSessionHas('status');
        $this->actingAs($this->moderator, 'admin')->post("/admin/reviews/{$id}/approve")->assertSessionHasErrors('review');   // pressed twice

        $row = DB::table('reviews')->where('public_id', $id)->first();
        expect($row->status)->toBe('published')->and($row->moderated_by)->toBe($this->moderator->id)->and($row->moderated_at)->not->toBeNull()->and(($this->stats)())->toBe(['count' => 1, 'average' => 4.0, 'stars' => [1 => 0, 2 => 0, 3 => 0, 4 => 1, 5 => 0]])
            ->and(DB::table('audit_events')->where('action', 'reviews.published')->count())->toBe(1);
    });

    it('rejects with a reason the author sees, and counts nothing', function () {
        $id = ($this->review)(1);
        $reviewer = DB::table('reviews')->where('public_id', $id)->value('user_id');

        $this->actingAs($this->moderator, 'admin')->post("/admin/reviews/{$id}/reject", ['reason' => ''])->assertSessionHasErrors('reason');
        expect(($this->status)($id))->toBe('pending');

        $this->actingAs($this->moderator, 'admin')->post("/admin/reviews/{$id}/reject", ['reason' => 'Please review the item, not the delivery.'])->assertSessionHas('status');
        expect(($this->status)($id))->toBe('rejected')->and(($this->stats)()['count'])->toBe(0);
        $this->actingAs(User::query()->find($reviewer), 'web')->get('/en/account/reviews')->assertSee('Please review the item, not the delivery.');
    });

    it('takes a published review down, with a reason, and its stars stop counting', function () {
        $keep = ($this->review)(5);
        $down = ($this->review)(1);
        app(ModerateReview::class)->approve($this->staff, $keep);
        app(ModerateReview::class)->approve($this->staff, $down);
        expect(($this->stats)()['count'])->toBe(2);

        $this->actingAs($this->moderator, 'admin')->post("/admin/reviews/{$down}/reject", ['reason' => 'Found to be abusive.'])->assertSessionHas('status');

        expect(($this->stats)())->toBe(['count' => 1, 'average' => 5.0, 'stars' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 1]])->and(($this->status)($down))->toBe('rejected');
        $this->get('/en/p/'.$this->ebook['slug'])->assertDontSee('Found to be abusive');
    });

    it('cannot decide again once it is rejected or withdrawn', function () {
        $id = ($this->review)();
        app(ModerateReview::class)->reject($this->staff, $id, 'No.');

        $this->actingAs($this->moderator, 'admin')->post("/admin/reviews/{$id}/approve")->assertSessionHasErrors('review');
        $this->actingAs($this->moderator, 'admin')->post("/admin/reviews/{$id}/reject", ['reason' => 'again'])->assertSessionHasErrors('review');
        $this->actingAs($this->moderator, 'admin')->post('/admin/reviews/'.str_repeat('0', 26).'/approve')->assertSessionHasErrors('review');
        expect(($this->status)($id))->toBe('rejected');
    });

    it('keeps the star counts equal to a recount through any mix of decisions', function () {
        $ids = [];
        foreach ([5, 4, 4, 2, 1, 5, 3, 5] as $rating) {
            $ids[] = ($this->review)($rating);
            expect(($this->stats)()['stars'])->toBe(($this->recount)());
        }
        $moderate = app(ModerateReview::class);
        $steps = [
            fn () => $moderate->approve($this->staff, $ids[0]), fn () => $moderate->approve($this->staff, $ids[1]), fn () => $moderate->approve($this->staff, $ids[2]), fn () => $moderate->approve($this->staff, $ids[3]),
            fn () => $moderate->reject($this->staff, $ids[1], 'x'), fn () => $moderate->approve($this->staff, $ids[4]), fn () => $moderate->approve($this->staff, $ids[5]),
            fn () => app(WithdrawReview::class)->handle((int) DB::table('reviews')->where('public_id', $ids[2])->value('user_id'), $ids[2]),
            fn () => $moderate->reject($this->staff, $ids[6], 'pending one rejected'), fn () => $moderate->approve($this->staff, $ids[7]), fn () => $moderate->reject($this->staff, $ids[0], 'taken down'),
        ];
        foreach ($steps as $step) {
            $step();
            $stats = ($this->stats)();
            expect($stats['stars'])->toBe(($this->recount)())->and($stats['count'])->toBe(array_sum(($this->recount)()));
        }
        expect(($this->stats)()['count'])->toBe(4);   // 2 (ids[3]), 1, 5, 5 stars: ids[3], [4], [5], [7]
    });

    it('stops the transaction rather than let the counts drift', function () {
        $id = ($this->review)(3);
        app(ModerateReview::class)->approve($this->staff, $id);
        DB::table('product_rating_stats')->update(['stars_3' => 0]);   // the numbers have been damaged

        expect(fn () => app(ModerateReview::class)->reject($this->staff, $id, 'down'))->toThrow(LogicException::class);
        expect(($this->status)($id))->toBe('published');   // the decision was undone with the failed count
    });

    it('lets the database refuse a move the rules do not allow, and a refusal without a reason', function () {
        $id = ($this->review)();
        app(ModerateReview::class)->approve($this->staff, $id);
        $move = fn (string $to, array $more = []) => fn () => DB::table('reviews')->where('public_id', $id)->update(['status' => $to] + $more);

        expect($move('pending'))->toThrow(QueryException::class)->and($move('rejected'))->toThrow(QueryException::class)   // a refusal needs its reason
            ->and($move('rejected', ['rejection_reason' => 'x']))->not->toThrow(QueryException::class)->and($move('published', ['rejection_reason' => null]))->toThrow(QueryException::class)->and($move('withdrawn'))->toThrow(QueryException::class);
    });
});

describe('the product page', function () {
    it('shows published reviews with the stars summary, the author\'s short name and a verified badge, and nothing unpublished', function () {
        $shown = ($this->review)(5, null, 'This one is shown, a very clear book.');
        $waiting = ($this->review)(1, null, 'This one is still waiting for a decision.');
        $rejected = ($this->review)(2, null, 'This one was rejected by a moderator.');
        app(ModerateReview::class)->approve($this->staff, $shown);
        app(ModerateReview::class)->reject($this->staff, $rejected, 'No.');

        $page = $this->get('/en/p/'.$this->ebook['slug'])->assertOk();
        $page->assertSee('Customer reviews')->assertSee('This one is shown')->assertSee('Amina R.')->assertSee('Verified purchase')->assertSee('Rated 5.0 out of 5 from 1 reviews')
            ->assertDontSee('still waiting')->assertDontSee('was rejected');
        expect($waiting)->not->toBe($shown);
    });

    it('shows no reviews block for a product with none', function () {
        $this->get('/en/p/'.$this->ebook['slug'])->assertOk()->assertDontSee('Customer reviews');
    });

    it('shows each review as it was written, and says so when it is in the other language', function () {
        $bn = ($this->review)(5, null, 'বইটি খুব সুন্দর, রান্নাগুলো পরিষ্কারভাবে লেখা।');
        $en = ($this->review)(4, null, 'A clear book with good recipes inside.');
        app(ModerateReview::class)->approve($this->staff, $bn);
        app(ModerateReview::class)->approve($this->staff, $en);

        $this->get('/en/p/'.$this->ebook['slug'])->assertSee('বইটি খুব সুন্দর')->assertSee('Written in Bangla')->assertSee('A clear book')->assertDontSee('Written in English');
        $bnSlug = (string) DB::table('product_translations')->where('product_id', $this->ebook['product']->id)->where('locale', 'bn')->value('slug');
        $this->get('/bn/p/'.$bnSlug)->assertSee('ইংরেজিতে লেখা')->assertSee('A clear book');
    });

    it('shows ten at a time, newest first, with the right distribution', function () {
        foreach (range(1, 12) as $i) {
            app(ModerateReview::class)->approve($this->staff, ($this->review)($i % 5 + 1, null, "Review text number {$i} for the e-book."));
        }

        $first = $this->get('/en/p/'.$this->ebook['slug'])->getContent();
        expect($first)->toContain('Review text number 12')->and($first)->not->toContain('Review text number 2 ')->and(substr_count($first, 'Review text number'))->toBe(10);
        $second = $this->get('/en/p/'.$this->ebook['slug'].'?reviews_page=2')->getContent();
        expect(substr_count($second, 'Review text number'))->toBe(2)->and($second)->toContain('Review text number 1 ');
        expect(($this->stats)()['count'])->toBe(12);
    });

    it('reads a long list of reviews in the same number of queries as a short one', function () {
        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get('/en/p/'.$this->ebook['slug'])->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        app(ModerateReview::class)->approve($this->staff, ($this->review)());
        $queries();
        $few = $queries();
        foreach (range(1, 8) as $i) {
            app(ModerateReview::class)->approve($this->staff, ($this->review)());
        }
        $queries();

        expect($queries())->toBe($few);
    });
});

describe('privacy', function () {
    it('puts the customer\'s reviews in their data copy and deletes them on erasure, lowering the star counts', function () {
        [$user, $order] = ($this->buyer)('Amina Rahman');
        $mine = app(SubmitReview::class)->handle($user->id, $order->public_id, $this->ebook['publicId'], 2, null, 'My own review of the e-book, honest.');
        $pending = null;
        app(ModerateReview::class)->approve($this->staff, $mine);
        $theirs = ($this->review)(5);
        app(ModerateReview::class)->approve($this->staff, $theirs);
        expect(($this->stats)()['count'])->toBe(2);

        $copy = app(EngagementPersonalData::class)->export($user->id);
        expect($copy['reviews'])->toHaveCount(1)->and($copy['reviews'][0]['rating'])->toBe(2)->and($copy['reviews'][0]['item'])->toBe('Cooking e-book')->and(json_encode($copy))->not->toContain('Review number');

        app(EngagementPersonalData::class)->erase($user->id);
        app(EngagementPersonalData::class)->erase($user->id);   // safe to repeat

        expect(DB::table('reviews')->where('user_id', $user->id)->count())->toBe(0)->and(($this->status)($theirs))->toBe('published')->and(($this->stats)()['stars'])->toBe([1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 1])->and($pending)->toBeNull();
    });
});
