<?php

declare(strict_types=1);

use App\Modules\Catalog\Actions\SaveCategory;
use App\Modules\Catalog\Actions\SaveProduct;
use App\Modules\Catalog\DTOs\ProductData;
use App\Modules\Catalog\DTOs\TranslationInput;
use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\DigitalProducts\Actions\GrantEntitlements;
use App\Modules\DigitalProducts\Listeners\GrantEntitlementsForPaidOrder;
use App\Modules\DigitalProducts\Models\Entitlement;
use App\Modules\Orders\Actions\CreateOrder;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\DTOs\NewOrder;
use App\Modules\Orders\DTOs\NewOrderAddress;
use App\Modules\Orders\DTOs\NewOrderLine;
use App\Modules\Orders\Enums\OrderChannel;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\PaymentStatus;
use App\Modules\Orders\Events\OrderPaid;
use App\Modules\Orders\Exceptions\OrderException;
use App\Modules\Payments\Database\Seeders\PaymentMethodSeeder;
use App\Modules\Payments\Services\DatabasePaymentMethodCatalog;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Outbox\OutboxDispatcher;
use App\Modules\Platform\Support\Settings;
use App\Modules\Pricing\Database\Seeders\TaxClassSeeder;
use App\Modules\Pricing\Services\BreakdownCalculator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed([LanguageSeeder::class, TaxClassSeeder::class, PaymentMethodSeeder::class]);
    $system = Actor::system('t');
    $category = app(SaveCategory::class)->handle($system, null, null, [new TranslationInput('en', 'General')]);
    $make = fn (string $sku, ProductType $type): int => (int) app(SaveProduct::class)->handle($system, null, new ProductData($type, [new TranslationInput('en', $sku), new TranslationInput('bn', $sku)], categoryIds: [$category->id], defaultVariant: new VariantData($sku)))->variants()->value('id');
    $this->book = $make('BOOK-1', ProductType::Digital);
    $this->book2 = $make('BOOK-2', ProductType::Digital);
    $this->tee = $make('TEE-1', ProductType::Physical);
    $this->types = [$this->book => 'digital', $this->book2 => 'digital', $this->tee => 'physical'];

    /** An order of the given variants (id => quantity). Not paid and not confirmed until asked. */
    $this->place = function (array $lines, bool $pay = false): object {
        $calc = [];
        $newLines = [];
        foreach ($lines as $variantId => $qty) {
            $calc[] = ['variantId' => $variantId, 'quantity' => $qty, 'unitMinor' => 35000, 'lineDiscountMinor' => 0, 'taxRateBp' => 0];
            $newLines[] = new NewOrderLine($variantId, $this->types[$variantId], ['en' => 'Item', 'bn' => 'পণ্য'], 'SKU-'.$variantId);
        }
        $breakdown = (new BreakdownCalculator)->calculate($calc, false, 0, 0, 0);
        $addresses = in_array('physical', array_map(fn ($v) => $this->types[$v], array_keys($lines)), true) ? [new NewOrderAddress('shipping', 'Rahim', '+8801712345678', 3, 30, 300, ['division' => ['en' => 'Dhaka', 'bn' => 'ঢাকা'], 'district' => ['en' => 'Dhaka', 'bn' => 'ঢাকা'], 'upazila' => ['en' => 'D', 'bn' => 'ড']], 'House 5', '1205', 'D', null)] : [];
        $order = app(CreateOrder::class)->handle(new NewOrder(Actor::guest('t'), null, 'Rahim', '+8801712345678', null, OrderChannel::Web, 'en', 'online', $this->types[array_key_first($lines)] === 'physical' && $addresses !== [] ? 'standard' : null, $breakdown, $newLines, $addresses));
        if ($pay) {
            ($this->pay)($order->id);
        }

        return $order;
    };
    $this->pay = function (int $orderId) use ($system) {
        app(TransitionOrder::class)->status($system, $orderId, OrderStatus::Confirmed, 'test');
        app(TransitionOrder::class)->payment($system, $orderId, PaymentStatus::Paid, 'test');
    };
    $this->deliver = fn () => app(OutboxDispatcher::class)->dispatchDue();   // what the scheduler / after-commit job does
    $this->rights = fn () => Entitlement::query()->orderBy('id')->get();
});

describe('telling that an order is paid', function () {
    it('announces it once, when the payment first becomes paid, and not before', function () {
        $order = ($this->place)([$this->book => 1]);
        expect(DB::table('outbox_messages')->where('event_name', 'orders.paid')->count())->toBe(0);

        app(TransitionOrder::class)->status(Actor::system('t'), $order->id, OrderStatus::Confirmed, 'x');
        app(TransitionOrder::class)->payment(Actor::system('t'), $order->id, PaymentStatus::Pending, 'x');
        expect(DB::table('outbox_messages')->where('event_name', 'orders.paid')->count())->toBe(0);

        app(TransitionOrder::class)->payment(Actor::system('t'), $order->id, PaymentStatus::Paid, 'x');
        expect(fn () => app(TransitionOrder::class)->payment(Actor::system('t'), $order->id, PaymentStatus::Paid, 'again'))->toThrow(OrderException::class);

        $rows = DB::table('outbox_messages')->where('event_name', 'orders.paid')->get();
        expect($rows)->toHaveCount(1)->and(json_decode($rows[0]->payload, true)['order_id'])->toBe($order->id);
    });
});

describe('the right to download', function () {
    it('is made for each digital line when the order is paid, with the shop\'s default rules', function () {
        $order = ($this->place)([$this->book => 1, $this->book2 => 2]);
        ($this->deliver)();
        expect(($this->rights)())->toHaveCount(0);   // nothing is owed before payment

        ($this->pay)($order->id);
        ($this->deliver)();

        $rights = ($this->rights)();
        expect($rights)->toHaveCount(2)->and($rights->pluck('variant_id')->all())->toBe([$this->book, $this->book2])->and($rights[0]->order_id)->toBe($order->id)
            ->and($rights[0]->status->value)->toBe('active')->and($rights[0]->download_limit)->toBe(5)->and($rights[0]->downloads_used)->toBe(0)->and($rights[0]->expires_at)->toBeNull()
            ->and($rights[1]->download_limit)->toBe(5);   // two copies of one line is still one right (keys per copy come with licence keys)
        expect(DB::table('audit_events')->where('action', 'digital.entitlement_granted')->count())->toBe(2);
    });

    it('takes the number of downloads and the time allowed from the product\'s own rule', function () {
        DB::table('digital_assets')->insert(['public_id' => strtolower((string) Str::ulid()), 'variant_id' => $this->book, 'download_limit' => 3, 'access_days' => 30, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('digital_assets')->insert(['public_id' => strtolower((string) Str::ulid()), 'variant_id' => $this->book2, 'download_limit' => null, 'access_days' => null, 'created_at' => now(), 'updated_at' => now()]);
        app(Settings::class)->set('digital.download_limit', 8);

        ($this->place)([$this->book => 1, $this->book2 => 1], true);
        ($this->deliver)();

        [$one, $two] = ($this->rights)()->all();
        expect($one->download_limit)->toBe(3)->and($one->expires_at->diffInSeconds($one->granted_at->addDays(30)))->toBeLessThan(2)->and($two->download_limit)->toBe(8)->and($two->expires_at)->toBeNull();   // no limit on the product: the shop's setting
    });

    it('is made even when no file has been uploaded yet', function () {
        ($this->place)([$this->book => 1], true);
        ($this->deliver)();

        expect(($this->rights)())->toHaveCount(1)->and(DB::table('digital_assets')->count())->toBe(0);
    });

    it('is made only for the digital lines of a mixed order, and not at all for a physical order', function () {
        ($this->place)([$this->tee => 1, $this->book => 1], true);
        ($this->place)([$this->tee => 1], true);
        ($this->deliver)();

        expect(($this->rights)())->toHaveCount(1)->and(($this->rights)()[0]->variant_id)->toBe($this->book);
    });

    it('is never made for an order that is not paid, whoever asks', function () {
        $order = ($this->place)([$this->book => 1]);

        expect(app(GrantEntitlements::class)->handle($order->id))->toBe(0)->and(app(GrantEntitlements::class)->handle(999999))->toBe(0)->and(($this->rights)())->toHaveCount(0);
    });

    it('is made once, however often the announcement is delivered or the grant is run', function () {
        $order = ($this->place)([$this->book => 1], true);
        ($this->deliver)();
        ($this->deliver)();
        $first = app(GrantEntitlements::class)->handle($order->id);
        $second = app(GrantEntitlements::class)->handle($order->id);

        expect($first)->toBe(0)->and($second)->toBe(0)->and(($this->rights)())->toHaveCount(1)->and(DB::table('audit_events')->where('action', 'digital.entitlement_granted')->count())->toBe(1);
        $message = DB::table('outbox_messages')->where('event_name', 'orders.paid')->first();
        $event = OrderPaid::fromPayload(json_decode($message->payload, true));
        $event->outboxId = (int) $message->id;
        app(GrantEntitlementsForPaidOrder::class)->handle($event);   // delivered again by hand
        expect(($this->rights)())->toHaveCount(1);
    });
});

describe('what the database itself refuses', function () {
    it('never lets the downloads used pass the limit, and a revoked right needs its time', function () {
        ($this->place)([$this->book => 1], true);
        ($this->deliver)();
        $id = ($this->rights)()[0]->id;

        DB::table('entitlements')->where('id', $id)->update(['downloads_used' => 5]);   // exactly the limit: fine
        expect(fn () => DB::table('entitlements')->where('id', $id)->update(['downloads_used' => 6]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('entitlements')->where('id', $id)->update(['status' => 'revoked']))->toThrow(QueryException::class)   // no time of revoking
            ->and(fn () => DB::table('entitlements')->where('id', $id)->update(['status' => 'expired']))->toThrow(QueryException::class);   // expiry is never stored
    });

    it('lets a right be revoked, and never come back, and gives one right per order line', function () {
        $order = ($this->place)([$this->book => 1], true);
        ($this->deliver)();
        $right = ($this->rights)()[0];

        DB::table('entitlements')->where('id', $right->id)->update(['status' => 'revoked', 'revoked_at' => now(), 'revoked_reason' => 'refund']);
        expect(fn () => DB::table('entitlements')->where('id', $right->id)->update(['status' => 'active']))->toThrow(QueryException::class)
            ->and(fn () => DB::table('entitlements')->insert(['public_id' => strtolower((string) Str::ulid()), 'order_id' => $order->id, 'order_item_id' => $right->order_item_id, 'variant_id' => $this->book, 'status' => 'active', 'downloads_used' => 0, 'granted_at' => now(), 'created_at' => now(), 'updated_at' => now()]))->toThrow(QueryException::class);
    });
});

describe('cash is never for a download or a booking', function () {
    beforeEach(function () {
        DB::table('payment_methods')->where('code', 'online')->update(['provider' => 'fake', 'is_enabled' => true]);
        $this->offered = fn (array $types) => collect(app(DatabasePaymentMethodCatalog::class)->options(100000, $types, 'en'))->pluck('code')->all();
    });

    it('offers cash only when everything in the cart is physical', function () {
        expect(($this->offered)(['physical']))->toContain('cod')->toContain('online')
            ->and(($this->offered)(['digital']))->not->toContain('cod')->toContain('online')
            ->and(($this->offered)(['service']))->not->toContain('cod')->toContain('online')
            ->and(($this->offered)(['physical', 'digital']))->not->toContain('cod')->toContain('online')
            ->and(($this->offered)(['physical', 'service']))->not->toContain('cod');
    });

    it('offers nothing for a download when online payment is paused or off', function () {
        DB::table('payment_methods')->where('code', 'online')->update(['is_enabled' => false]);
        expect(($this->offered)(['digital']))->toBe([])->and(($this->offered)(['physical']))->toBe(['cod']);

        DB::table('payment_methods')->where('code', 'online')->update(['is_enabled' => true]);
        app(Settings::class)->set(DatabasePaymentMethodCatalog::FREEZE_SETTING, true);
        expect(($this->offered)(['digital']))->toBe([]);
    });

    it('does not let a method set up with no restriction offer cash for a download', function () {
        DB::table('payment_methods')->where('code', 'cod')->update(['restrictions' => null]);

        expect(($this->offered)(['digital']))->not->toContain('cod');
    });
});
