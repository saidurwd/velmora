<?php

declare(strict_types=1);

use App\Modules\Catalog\Actions\TransitionProduct;
use App\Modules\Catalog\Models\Product;
use App\Modules\DigitalProducts\Actions\ImportLicenseKeys;
use App\Modules\DigitalProducts\Actions\RevokeEntitlements;
use App\Modules\DigitalProducts\Contracts\RefundPolicyTexts;
use App\Modules\DigitalProducts\Listeners\RevokeForRefundedOrder;
use App\Modules\DigitalProducts\Models\Entitlement;
use App\Modules\DigitalProducts\Models\RefundPolicy;
use App\Modules\DigitalProducts\Services\RefundPolicies;
use App\Modules\DigitalProducts\Services\RefundVerdict;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\Enums\PaymentStatus;
use App\Modules\Orders\Events\OrderRefunded;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Outbox\OutboxDispatcher;
use App\Modules\Pricing\Actions\SetRegularPrice;
use App\Modules\Pricing\Database\Seeders\PriceListSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    digitalWorld($this);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    $this->owner = User::factory()->create(['email' => 'owner@example.com']);
    $this->staff = Actor::system('t');
    $this->buy = function (int $qty = 1, string $sku = 'BOOK-1') {
        $order = ($this->place)([($sku === 'BOOK-1' ? $this->book : $this->book2) => $qty], $this->owner->id);
        ($this->pay)($order->id);

        return [$order, DB::table('entitlements')->where('order_id', $order->id)->first()];
    };
    $this->right = fn (int $id) => Entitlement::query()->findOrFail($id);
    $this->policy = fn () => RefundPolicy::query()->with('translations')->where('is_default', true)->firstOrFail();
    $this->verdict = fn (int $id, ?RefundPolicy $policy = null) => app(RefundVerdict::class)->for(($this->right)($id), $policy ?? ($this->policy)(), now()->toImmutable());
    $this->refund = function (object $order, bool $full) {
        $t = app(TransitionOrder::class);
        $t->payment($this->staff, $order->id, $full ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded, 'test');
        app(OutboxDispatcher::class)->dispatchDue();
    };
    $this->download = fn (object $right) => $this->actingAs($this->owner)->post("/en/account/library/{$right->public_id}/download");
});

describe('the first use', function () {
    it('is recorded once, by the first download, and never changes after', function () {
        ($this->upload)('BOOK-1');
        [, $right] = ($this->buy)();
        expect(($this->right)($right->id)->first_used_at)->toBeNull();

        ($this->download)($right)->assertStatus(303);
        $first = ($this->right)($right->id)->first_used_at;
        $this->travel(2)->hours();
        ($this->download)($right)->assertStatus(303);

        expect($first)->not->toBeNull()->and(($this->right)($right->id)->first_used_at->equalTo($first))->toBeTrue()
            ->and(fn () => DB::table('entitlements')->where('id', $right->id)->update(['first_used_at' => now()->addYear()]))->toThrow(QueryException::class);
    });

    it('is also recorded by the first look at the keys', function () {
        app(ImportLicenseKeys::class)->handle($this->staff, 'BOOK-2', "K-1\nK-2");
        [, $right] = ($this->buy)(1, 'BOOK-2');

        $this->actingAs($this->owner)->post("/en/account/library/{$right->public_id}/keys")->assertOk();

        expect(($this->right)($right->id)->first_used_at)->not->toBeNull();
    });
});

describe('what the refund policy says', function () {
    it('is refundable until first use under the default policy, and not after', function () {
        ($this->upload)('BOOK-1');
        [, $right] = ($this->buy)();

        expect(($this->verdict)($right->id))->toBe(['refundable' => true, 'reason' => 'unused']);
        ($this->download)($right);
        expect(($this->verdict)($right->id))->toBe(['refundable' => false, 'reason' => 'used']);
    });

    it('is never refundable under the never-refundable rule, and refundable for a number of days under the time rule', function () {
        [, $right] = ($this->buy)();
        $never = new RefundPolicy(['rule' => 'non_refundable']);
        $days = new RefundPolicy(['rule' => 'within_days', 'days' => 7]);

        expect(($this->verdict)($right->id, $never))->toBe(['refundable' => false, 'reason' => 'not_refundable'])->and(($this->verdict)($right->id, $days))->toBe(['refundable' => true, 'reason' => 'within_window']);
        $this->travel(8)->days();
        expect(($this->verdict)($right->id, $days))->toBe(['refundable' => false, 'reason' => 'window_closed']);
    });

    it('uses a product\'s own policy when it has one, else the default', function () {
        $own = RefundPolicy::query()->create(['public_id' => strtolower((string) Str::ulid()), 'code' => 'own', 'rule' => 'non_refundable', 'revoke_on_full_refund' => true, 'is_default' => false]);
        DB::table('digital_assets')->insert(['public_id' => strtolower((string) Str::ulid()), 'variant_id' => $this->book, 'refund_policy_id' => $own->id, 'created_at' => now(), 'updated_at' => now()]);

        $policies = app(RefundPolicies::class)->forVariants([$this->book, $this->book2]);

        expect($policies[$this->book]->code)->toBe('own')->and($policies[$this->book2]->code)->toBe('default');
    });
});

describe('what the customer reads before buying', function () {
    it('is on the product page of a digital product, in both languages, from the policy records, and not on a physical product', function () {
        $this->seed(PriceListSeeder::class);
        foreach ([$this->book, $this->tee] as $variant) {
            app(SetRegularPrice::class)->handle($this->staff, $variant, Money::of(35000));
            app(TransitionProduct::class)->publish($this->staff, Product::query()->whereHas('variants', fn ($q) => $q->whereKey($variant))->firstOrFail());
        }
        $slug = fn (int $variant, string $locale) => (string) DB::table('product_translations as t')->join('product_variants as v', 'v.product_id', '=', 't.product_id')->where('v.id', $variant)->where('t.locale', $locale)->value('t.slug');

        $this->get('/en/p/'.$slug($this->book, 'en'))->assertOk()->assertSee('Refunds for digital products')->assertSee('until you first download the file');
        $this->get('/bn/p/'.rawurlencode($slug($this->book, 'bn')))->assertOk()->assertSee('ডিজিটাল পণ্যের রিফান্ড');
        $this->get('/en/p/'.$slug($this->tee, 'en'))->assertOk()->assertDontSee('Refunds for digital products');

        DB::table('refund_policy_translations')->where('locale', 'en')->update(['text' => 'Changed words.']);   // the words are data: a change shows at once
        $this->get('/en/p/'.$slug($this->book, 'en'))->assertOk()->assertSee('Changed words.');
    });

    it('is shown once per policy, for the digital items in a cart, in the customer\'s language, and nothing for a cart without any', function () {
        $texts = app(RefundPolicyTexts::class);

        expect($texts->forSkus(['BOOK-1', 'BOOK-2', 'TEE-1'], 'en'))->toHaveCount(1)->and($texts->forSkus(['BOOK-1'], 'bn')[0]['title'])->toBe('ডিজিটাল পণ্যের রিফান্ড')
            ->and($texts->forSkus(['TEE-1'], 'en'))->toBe([])->and($texts->forSkus([], 'en'))->toBe([]);
        DB::table('refund_policy_translations')->where('locale', 'bn')->delete();
        expect($texts->forSkus(['BOOK-1'], 'bn')[0]['title'])->toBe('Refunds for digital products');   // no Bangla words: English is shown
    });
});

describe('a refund of the whole order', function () {
    it('announces the refund once, in full or in part', function () {
        [$whole] = ($this->buy)();
        [$part] = ($this->buy)(1, 'BOOK-2');

        ($this->refund)($part, false);
        ($this->refund)($whole, true);

        $events = DB::table('outbox_messages')->where('event_name', 'orders.refunded')->orderBy('id')->get()->map(fn ($m) => json_decode($m->payload, true));
        expect($events)->toHaveCount(2)->and($events[0]['full'])->toBeFalse()->and($events[1]['full'])->toBeTrue();
    });

    it('takes away the downloads and the keys of that order, and nothing else', function () {
        app(ImportLicenseKeys::class)->handle($this->staff, 'BOOK-1', "K-1\nK-2\nK-3");
        ($this->upload)('BOOK-1');
        [$order, $right] = ($this->buy)(2);
        [, $other] = ($this->buy)(1);

        ($this->refund)($order, true);

        $gone = ($this->right)($right->id);
        expect($gone->status->value)->toBe('revoked')->and($gone->revoked_reason)->toBe('refund')->and($gone->revoked_at)->not->toBeNull()
            ->and(DB::table('license_keys')->where('entitlement_id', $right->id)->pluck('status')->unique()->all())->toBe(['revoked'])
            ->and(($this->right)($other->id)->status->value)->toBe('active')->and(DB::table('license_keys')->where('entitlement_id', $other->id)->value('status'))->toBe('allocated')
            ->and(DB::table('license_keys')->where('status', 'available')->count())->toBe(0);   // withdrawn keys are never given to anyone else
        ($this->download)($right)->assertSessionHasErrors('download');   // and a download is refused from then on
        $this->actingAs($this->owner)->post("/en/account/library/{$right->public_id}/keys")->assertSessionHasErrors('download');
        expect(DB::table('audit_events')->where('action', 'digital.entitlement_revoked')->count())->toBe(1);
    });

    it('leaves the downloads alone after a partial refund, and when the product\'s policy says to keep them', function () {
        [$partial, $a] = ($this->buy)();
        ($this->refund)($partial, false);
        expect(($this->right)($a->id)->status->value)->toBe('active');

        $keep = RefundPolicy::query()->create(['public_id' => strtolower((string) Str::ulid()), 'code' => 'keep', 'rule' => 'non_refundable', 'revoke_on_full_refund' => false, 'is_default' => false]);
        DB::table('digital_assets')->insert(['public_id' => strtolower((string) Str::ulid()), 'variant_id' => $this->book2, 'refund_policy_id' => $keep->id, 'created_at' => now(), 'updated_at' => now()]);
        [$whole, $b] = ($this->buy)(1, 'BOOK-2');
        ($this->refund)($whole, true);

        expect(($this->right)($b->id)->status->value)->toBe('active');
    });

    it('is harmless when the refund is announced twice, and does nothing for an order with no downloads', function () {
        [$order, $right] = ($this->buy)();
        ($this->refund)($order, true);
        $message = DB::table('outbox_messages')->where('event_name', 'orders.refunded')->first();
        $event = OrderRefunded::fromPayload(json_decode($message->payload, true));
        $event->outboxId = (int) $message->id;

        app(RevokeForRefundedOrder::class)->handle($event);   // delivered again by hand
        $physical = ($this->place)([$this->tee => 1]);
        ($this->pay)($physical->id);
        ($this->refund)($physical, true);

        expect(DB::table('audit_events')->where('action', 'digital.entitlement_revoked')->count())->toBe(1)->and(app(RevokeEntitlements::class)->forRefundedOrder($physical->id))->toBe(0);
    });

    it('never lets a taken-away right come back', function () {
        [$order, $right] = ($this->buy)();
        ($this->refund)($order, true);

        expect(fn () => DB::table('entitlements')->where('id', $right->id)->update(['status' => 'active']))->toThrow(QueryException::class);
    });
});
