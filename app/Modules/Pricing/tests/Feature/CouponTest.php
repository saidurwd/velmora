<?php

declare(strict_types=1);

use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\Support\FrozenClock;
use App\Modules\Pricing\Actions\RedeemCoupons;
use App\Modules\Pricing\Contracts\DiscountEvaluator;
use App\Modules\Pricing\DTOs\AppliedDiscount;
use App\Modules\Pricing\DTOs\DiscountLine;
use App\Modules\Pricing\DTOs\DiscountOutcome;
use App\Modules\Pricing\DTOs\DiscountRequest;
use App\Modules\Pricing\Exceptions\CouponException;
use App\Modules\Pricing\Services\BreakdownCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(LanguageSeeder::class);
    $this->clock = new FrozenClock(CarbonImmutable::parse('2026-12-01 06:00:00', 'UTC'));
    $this->app->instance(Clock::class, $this->clock);

    $this->coupon = function (string $code, array $over = []): int {
        return DB::table('coupons')->insertGetId($over + ['public_id' => strtolower((string) Str::ulid()), 'code' => $code, 'type' => 'percent', 'value' => 1000, 'scope' => 'all', 'is_active' => true, 'min_spend_minor' => 0, 'used_count' => 0, 'created_at' => now(), 'updated_at' => now()]);
    };
    // two lines: shirt (product 10, in categories 1 and its parent 2) ৳100 and mug (product 20, category 3) ৳50
    $this->lines = [new DiscountLine(101, 10, [1, 2], 10000), new DiscountLine(201, 20, [3], 5000)];
    $this->eval = fn (array $codes, ?string $redeemer = null, ?array $lines = null, string $locale = 'en') => app(DiscountEvaluator::class)->evaluate(new DiscountRequest($lines ?? $this->lines, $codes, $redeemer, $locale));
});

describe('amounts', function () {
    it('takes a percentage of the goods and splits it to the paisa across the lines', function () {
        ($this->coupon)('SAVE10');
        $o = ($this->eval)(['SAVE10']);

        expect($o->lineDiscounts)->toBe([101 => 1000, 201 => 500])->and($o->totalMinor())->toBe(1500)->and($o->acceptedCodes())->toBe(['SAVE10'])->and($o->rejected)->toBe([]);
    });

    it('never leaves a stray paisa when the split does not divide evenly', function () {
        ($this->coupon)('ODD', ['type' => 'fixed', 'value' => 1000]);
        $o = ($this->eval)(['ODD'], null, [new DiscountLine(1, 1, [], 3333), new DiscountLine(2, 2, [], 3333), new DiscountLine(3, 3, [], 3334)]);

        expect(array_sum($o->lineDiscounts))->toBe(1000)->and($o->lineDiscounts)->toBe([1 => 334, 2 => 333, 3 => 333]);
    });

    it('caps a percentage, and never gives a fixed amount larger than the goods', function () {
        ($this->coupon)('CAPPED', ['value' => 5000, 'max_discount_minor' => 2000]);
        ($this->coupon)('BIGFIX', ['type' => 'fixed', 'value' => 99999]);

        expect(($this->eval)(['CAPPED'])->totalMinor())->toBe(2000)->and(($this->eval)(['BIGFIX'])->totalMinor())->toBe(15000);
    });

    it('reads codes the way people type them: any case, spaces, Bangla digits', function () {
        ($this->coupon)('SAVE10');
        ($this->coupon)('EID25');

        expect(($this->eval)(['  save 10 '])->acceptedCodes())->toBe(['SAVE10'])->and(($this->eval)(['eid২৫'])->acceptedCodes())->toBe(['EID25'])
            ->and(($this->eval)(['!!'])->rejected[0]['reason'])->toBe('unknown');
    });
});

describe('who and what it applies to', function () {
    it('applies a product coupon only to that product, and measures its amount on it alone', function () {
        $id = ($this->coupon)('MUGS', ['scope' => 'products']);
        DB::table('products')->insert(['id' => 20, 'public_id' => strtolower((string) Str::ulid()), 'product_type' => 'physical', 'tax_class_code' => 'standard', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('coupon_products')->insert(['coupon_id' => $id, 'product_id' => 20]);

        $o = ($this->eval)(['MUGS']);
        expect($o->lineDiscounts)->toBe([201 => 500]);
    });

    it('applies a category coupon to every product in it, including sub-categories (ancestors are in the line)', function () {
        $cat = DB::table('categories')->insertGetId(['public_id' => strtolower((string) Str::ulid()), 'is_active' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $id = ($this->coupon)('CLOTHES', ['scope' => 'categories']);
        DB::table('coupon_categories')->insert(['coupon_id' => $id, 'category_id' => $cat]);
        $lines = [new DiscountLine(101, 10, [1, $cat], 10000), new DiscountLine(201, 20, [3], 5000)];

        expect(($this->eval)(['CLOTHES'], null, $lines)->lineDiscounts)->toBe([101 => 1000]);
    });

    it('says so when it applies to nothing in the cart', function () {
        $id = ($this->coupon)('NOPE', ['scope' => 'products']);
        DB::table('coupon_products')->insert(['coupon_id' => $id, 'product_id' => DB::table('products')->insertGetId(['public_id' => strtolower((string) Str::ulid()), 'product_type' => 'physical', 'tax_class_code' => 'standard', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()])]);

        expect(($this->eval)(['NOPE'])->rejected[0]['reason'])->toBe('not_applicable');
    });

    it('checks the minimum spend to the paisa', function () {
        ($this->coupon)('BIG', ['min_spend_minor' => 15000]);

        expect(($this->eval)(['BIG'])->acceptedCodes())->toBe(['BIG'])
            ->and(($this->eval)(['BIG'], null, [new DiscountLine(101, 10, [], 14999)])->rejected[0])->toBe(['code' => 'BIG', 'reason' => 'min_spend', 'min' => 15000]);
    });
});

describe('when it is valid', function () {
    it('respects the start and the exclusive end', function () {
        ($this->coupon)('LATER', ['starts_at' => '2026-12-01 06:00:01']);
        ($this->coupon)('SOON', ['ends_at' => '2026-12-01 06:00:00']);   // ends the very instant it is now
        ($this->coupon)('NOW', ['starts_at' => '2026-12-01 06:00:00', 'ends_at' => '2026-12-01 06:00:01']);

        expect(($this->eval)(['LATER'])->rejected[0]['reason'])->toBe('not_started')->and(($this->eval)(['SOON'])->rejected[0]['reason'])->toBe('expired')->and(($this->eval)(['NOW'])->acceptedCodes())->toBe(['NOW']);
    });

    it('refuses inactive, used-up and unknown codes', function () {
        ($this->coupon)('OFF', ['is_active' => false]);
        ($this->coupon)('GONE', ['usage_limit' => 5, 'used_count' => 5]);
        ($this->coupon)('LAST', ['usage_limit' => 5, 'used_count' => 4]);

        expect(array_column(($this->eval)(['OFF', 'GONE'])->rejected, 'reason', 'code'))->toBe(['OFF' => 'inactive', 'GONE' => 'exhausted'])
            ->and(($this->eval)(['LAST'])->acceptedCodes())->toBe(['LAST'])->and(($this->eval)(['NOSUCH'])->rejected[0]['reason'])->toBe('unknown');
    });

    it('knows when a customer has used a code up (once they are identified)', function () {
        $id = ($this->coupon)('ONCE', ['per_customer_limit' => 1]);
        DB::table('coupon_redemptions')->insert(['coupon_id' => $id, 'order_id' => 1, 'redeemer' => 'u:7', 'amount_minor' => 1, 'redeemed_at' => now()]);

        expect(($this->eval)(['ONCE'], 'u:7')->rejected[0]['reason'])->toBe('per_customer_limit')->and(($this->eval)(['ONCE'], 'u:8')->acceptedCodes())->toBe(['ONCE'])
            ->and(($this->eval)(['ONCE'], null)->acceptedCodes())->toBe(['ONCE']);  // unknown shopper: enforced when the order is placed
    });
});

describe('using several', function () {
    it('combines one discount code with one free-delivery code, and no more', function () {
        ($this->coupon)('TENOFF');
        ($this->coupon)('FIVEOFF', ['type' => 'fixed', 'value' => 500]);
        ($this->coupon)('SHIPFREE', ['type' => 'free_shipping', 'value' => 0]);

        $both = ($this->eval)(['TENOFF', 'SHIPFREE']);
        expect($both->acceptedCodes())->toBe(['TENOFF', 'SHIPFREE'])->and($both->freeShipping)->toBeTrue()->and($both->totalMinor())->toBe(1500);

        $two = ($this->eval)(['TENOFF', 'FIVEOFF']);
        expect($two->acceptedCodes())->toBe(['TENOFF'])->and($two->rejected[0])->toMatchArray(['code' => 'FIVEOFF', 'reason' => 'not_combinable']);

        expect(($this->eval)(['TENOFF', 'SHIPFREE', 'FIVEOFF'])->rejected[0])->toMatchArray(['code' => 'FIVEOFF', 'reason' => 'too_many']);
    });

    it('sets the free-delivery flag without any money off', function () {
        ($this->coupon)('SHIPFREE', ['type' => 'free_shipping', 'value' => 0]);
        $o = ($this->eval)(['SHIPFREE']);

        expect($o->freeShipping)->toBeTrue()->and($o->totalMinor())->toBe(0);
    });
});

describe('automatic promotions', function () {
    it('applies the best qualifying promotion, labelled in the shopper\'s language', function () {
        $small = DB::table('promotions')->insertGetId(['public_id' => strtolower((string) Str::ulid()), 'type' => 'percent', 'value' => 500, 'min_spend_minor' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $big = DB::table('promotions')->insertGetId(['public_id' => strtolower((string) Str::ulid()), 'type' => 'fixed', 'value' => 2000, 'min_spend_minor' => 10000, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $tooBig = DB::table('promotions')->insertGetId(['public_id' => strtolower((string) Str::ulid()), 'type' => 'percent', 'value' => 5000, 'min_spend_minor' => 99999, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('promotion_translations')->insert([['promotion_id' => $small, 'locale' => 'en', 'label' => '5% off'], ['promotion_id' => $big, 'locale' => 'en', 'label' => '৳20 off over ৳100'], ['promotion_id' => $big, 'locale' => 'bn', 'label' => '১০০ টাকার ওপরে ২০ টাকা ছাড়'], ['promotion_id' => $tooBig, 'locale' => 'en', 'label' => 'half price']]);

        $en = ($this->eval)([]);
        expect($en->orderDiscountMinor)->toBe(2000)->and($en->applied[0]->label)->toBe('৳20 off over ৳100')->and($en->applied[0]->kind)->toBe('promotion')
            ->and(($this->eval)([], null, null, 'bn')->applied[0]->label)->toBe('১০০ টাকার ওপরে ২০ টাকা ছাড়');

        DB::table('promotions')->where('id', $big)->update(['ends_at' => '2026-12-01 06:00:00']);
        expect(($this->eval)([])->orderDiscountMinor)->toBe(750);   // falls back to the 5% one
    });

    it('stacks with a coupon, and the pricing engine keeps the total from going below zero', function () {
        ($this->coupon)('HALF', ['value' => 5000]);
        DB::table('promotions')->insert(['public_id' => strtolower((string) Str::ulid()), 'type' => 'percent', 'value' => 9000, 'min_spend_minor' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        $o = ($this->eval)(['HALF']);
        $b = (new BreakdownCalculator)->calculate(
            [['variantId' => 101, 'quantity' => 1, 'unitMinor' => 10000, 'lineDiscountMinor' => $o->lineDiscounts[101] ?? 0, 'taxRateBp' => 0], ['variantId' => 201, 'quantity' => 1, 'unitMinor' => 5000, 'lineDiscountMinor' => $o->lineDiscounts[201] ?? 0, 'taxRateBp' => 0]],
            true, $o->orderDiscountMinor, 6000, 0,
        );

        expect($b->grandTotalMinor)->toBe(6000)->and($b->reconciles())->toBeTrue();   // goods free, delivery still paid
    });
});

describe('redeeming', function () {
    beforeEach(function () {
        $this->outcome = fn (string $code, int $amount = 1000) => new DiscountOutcome(applied: [new AppliedDiscount('coupon', $code, $code, $amount)]);
        $this->redeem = fn (string $code, int $order, ?string $who = null, int $amount = 1000) => app(RedeemCoupons::class)->redeem(($this->outcome)($code, $amount), $order, $who);
    });

    it('takes one use, records who and how much, and is harmless to repeat for the same order', function () {
        $id = ($this->coupon)('TEN', ['usage_limit' => 3]);

        ($this->redeem)('TEN', 1, 'u:5', 1234);
        ($this->redeem)('TEN', 1, 'u:5', 1234);   // a retried order

        expect((int) DB::table('coupons')->where('id', $id)->value('used_count'))->toBe(1)
            ->and(DB::table('coupon_redemptions')->where('coupon_id', $id)->get(['order_id', 'redeemer', 'amount_minor'])->map(fn ($r) => (array) $r)->all())->toBe([['order_id' => 1, 'redeemer' => 'u:5', 'amount_minor' => 1234]]);
    });

    it('stops at the usage limit, and leaves nothing behind when it refuses', function () {
        $id = ($this->coupon)('TWO', ['usage_limit' => 2]);
        ($this->redeem)('TWO', 1);
        ($this->redeem)('TWO', 2);

        expect(fn () => ($this->redeem)('TWO', 3))->toThrow(CouponException::class, 'exhausted');
        expect((int) DB::table('coupons')->where('id', $id)->value('used_count'))->toBe(2)->and(DB::table('coupon_redemptions')->where('coupon_id', $id)->count())->toBe(2);
    });

    it('enforces the per-customer limit, and undoes the use it just took when that fails', function () {
        $id = ($this->coupon)('MINE', ['per_customer_limit' => 1, 'usage_limit' => 10]);
        ($this->redeem)('MINE', 1, 'u:5');
        ($this->redeem)('MINE', 2, 'u:6');   // someone else is fine

        expect(fn () => ($this->redeem)('MINE', 3, 'u:5'))->toThrow(CouponException::class, 'per_customer_limit');
        expect((int) DB::table('coupons')->where('id', $id)->value('used_count'))->toBe(2);
    });

    it('refuses an expired or switched-off coupon at the moment of ordering, even if the cart looked fine', function () {
        ($this->coupon)('LATE', ['ends_at' => '2026-12-01 06:00:00']);
        ($this->coupon)('OFF', ['is_active' => false]);

        expect(fn () => ($this->redeem)('LATE', 1))->toThrow(CouponException::class, 'expired')->and(fn () => ($this->redeem)('OFF', 2))->toThrow(CouponException::class, 'inactive')
            ->and(fn () => ($this->redeem)('GHOST', 3))->toThrow(CouponException::class, 'unknown');
    });

    it('is undone with the order when something later in the same transaction fails', function () {
        $id = ($this->coupon)('ROLL', ['usage_limit' => 1]);

        try {
            DB::transaction(function () {
                ($this->redeem)('ROLL', 1);
                throw new RuntimeException('stock ran out');
            });
        } catch (RuntimeException) {
        }

        expect((int) DB::table('coupons')->where('id', $id)->value('used_count'))->toBe(0)->and(DB::table('coupon_redemptions')->count())->toBe(0);
        ($this->redeem)('ROLL', 2);   // the use is still there for the next order
        expect((int) DB::table('coupons')->where('id', $id)->value('used_count'))->toBe(1);
    });

    it('redeems several coupons of one order, and all of them or none', function () {
        $a = ($this->coupon)('AAA', ['usage_limit' => 1]);
        $b = ($this->coupon)('BBB', ['usage_limit' => 1, 'used_count' => 1]);   // already used up
        $outcome = new DiscountOutcome(applied: [new AppliedDiscount('coupon', 'BBB', 'BBB', 100), new AppliedDiscount('coupon', 'AAA', 'AAA', 200), new AppliedDiscount('promotion', null, 'x', 300)]);

        expect(fn () => app(RedeemCoupons::class)->redeem($outcome, 9, null))->toThrow(CouponException::class);
        expect((int) DB::table('coupons')->where('id', $a)->value('used_count'))->toBe(0)->and(DB::table('coupon_redemptions')->count())->toBe(0);
    });

    it('cannot be changed after the fact: redemptions are append-only and the limit is also a database rule', function () {
        $id = ($this->coupon)('LOCK', ['usage_limit' => 1]);
        ($this->redeem)('LOCK', 1);

        expect(fn () => DB::table('coupon_redemptions')->where('coupon_id', $id)->update(['amount_minor' => 1]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('coupon_redemptions')->where('coupon_id', $id)->delete())->toThrow(QueryException::class)
            ->and(fn () => DB::table('coupons')->where('id', $id)->update(['used_count' => 2]))->toThrow(QueryException::class);
    });
});

it('rejects impossible coupon definitions at the database', function () {
    $bad = fn (array $over) => fn () => ($this->coupon)('BAD'.random_int(1, 99999), $over);

    expect($bad(['type' => 'bogus']))->toThrow(QueryException::class)->and($bad(['scope' => 'everything']))->toThrow(QueryException::class)
        ->and($bad(['value' => 10001]))->toThrow(QueryException::class)->and($bad(['value' => -1]))->toThrow(QueryException::class)
        ->and($bad(['starts_at' => '2026-12-02 00:00:00', 'ends_at' => '2026-12-01 00:00:00']))->toThrow(QueryException::class);
});
