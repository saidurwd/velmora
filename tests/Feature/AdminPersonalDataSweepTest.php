<?php

declare(strict_types=1);

use App\Modules\Bookings\Actions\HoldSlot;
use App\Modules\Bookings\Actions\SaveServiceDefinition;
use App\Modules\Bookings\Actions\SetWeeklyAvailability;
use App\Modules\Cart\Contracts\CartReader;
use App\Modules\Cart\DTOs\CartOwner;
use App\Modules\Catalog\Actions\TransitionProduct;
use App\Modules\Catalog\Models\Product;
use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Orders\Actions\PlaceOrder;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\DTOs\PlaceOrderCommand;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\PaymentStatus;
use App\Modules\Orders\Services\CheckoutPricing;
use App\Modules\Payments\Database\Seeders\PaymentMethodSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Outbox\OutboxDispatcher;
use App\Modules\Pricing\Actions\SetRegularPrice;
use App\Modules\Pricing\Database\Seeders\PriceListSeeder;
use App\Modules\Reporting\Reports\ReportCatalogue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpFoundation\StreamedResponse;

/*
 * Phase 10 gate: personal data in the admin. Staff who hold every right EXCEPT `customers.view_pii` must never see a customer's
 * full phone number or email address on any admin page or file; staff who hold it still see what they need to ring the customer.
 * The crawl visits every admin page that can be opened without picking a record, plus the order and booking pages.
 */

const SWEEP_PHONE_PARTS = ['01712345678', '8801712345678', '1712345678'];
const SWEEP_EMAIL = 'sweep.customer@example.com';

beforeEach(function () {
    bookingsWorld($this);
    $this->seed([RolesAndPermissionsSeeder::class, PriceListSeeder::class, PaymentMethodSeeder::class]);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    DB::table('payment_methods')->where('code', 'online')->update(['provider' => 'fake', 'is_enabled' => true]);
    config(['services.sms.driver' => 'fake']);
    Mail::fake();
    app(SetRegularPrice::class)->handle($this->staff, $this->variant30, Money::of(50000));
    app(TransitionProduct::class)->publish($this->staff, Product::query()->whereHas('variants', fn ($q) => $q->whereKey($this->variant30))->firstOrFail());
    $this->travelTo(CarbonImmutable::parse('2026-11-30 04:00:00', 'UTC'));   // exactly 24 hours before a 10:00 Dhaka start on Tuesday 1 December
    app(SaveServiceDefinition::class)->handle($this->staff, 'SVC-30', 'at_provider', 60, 60, 15, true);
    foreach ([$this->tailor, $this->room] as $resource) {
        app(SetWeeklyAvailability::class)->handle($this->staff, $resource, [['weekday' => 2, 'start' => '09:00', 'end' => '12:00']]);
    }
    $this->customer = User::factory()->create(['email' => 'o@example.com']);
    $this->manager = catalogStaff(['bookings.view', 'bookings.manage']);
    $this->viewer = catalogStaff(['bookings.view']);
    $this->nobody = catalogStaff(['catalog.view']);
    $this->local = fn (string $t) => CarbonImmutable::parse("2026-12-01 {$t}", 'Asia/Dhaka')->utc()->format('Y-m-d H:i:s');

    $this->booked = function (string $local = '10:00', ?string $resource = null) {
        $owner = CartOwner::user($this->customer->id);
        app(HoldSlot::class)->handle($owner, 'SVC-30', $resource ?? $this->tailor->public_id, ($this->local)($local));
        $pay = 'online';
        $order = app(PlaceOrder::class)->handle(new PlaceOrderCommand(
            actor: Actor::guest('checkout'), owner: $owner, userId: $this->customer->id, contactName: 'Rahim Uddin', contactPhone: '01712345678', contactEmail: SWEEP_EMAIL, address: null, shippingMethodCode: null, paymentMethodCode: $pay,
            expectedTotalMinor: app(CheckoutPricing::class)->quote(app(CartReader::class)->view($owner, 'en'), null, null, $pay, 'en')->breakdown->grandTotalMinor, placeToken: 'token-'.Str::random(12), locale: 'en',
        ));
        app(TransitionOrder::class)->status(Actor::system('t'), $order->id, OrderStatus::Confirmed, 'test');
        app(TransitionOrder::class)->payment(Actor::system('t'), $order->id, PaymentStatus::Paid, 'test');
        app(OutboxDispatcher::class)->dispatchDue();
        $this->lastOrder = $order;

        return (string) DB::table('service_bookings')->where('order_item_id', DB::table('order_items')->where('order_id', $order->id)->value('id'))->value('public_id');
    };
    $this->row = fn (string $publicId) => DB::table('service_bookings')->where('public_id', $publicId)->first();
    $this->as = fn (User $u) => $this->actingAs($u, 'admin');

    $this->swept = function () {
        $id = ($this->booked)();

        return [$id, $this->lastOrder];
    };
    $this->everyoneButPii = fn () => catalogStaff(array_values(array_diff(Permission::query()->pluck('name')->all(), ['customers.view_pii'])));
    $this->leaks = function (string $content): array {
        $found = [];
        foreach ([...SWEEP_PHONE_PARTS, SWEEP_EMAIL] as $needle) {
            if (str_contains($content, $needle)) {
                $found[] = $needle;
            }
        }

        return $found;
    };
});

it('shows no full phone or email to staff without the personal-data right, on the order and booking pages', function () {
    [$booking, $order] = ($this->swept)();
    $staff = ($this->everyoneButPii)();
    $order = DB::table('orders')->where('id', $order->id)->first();

    foreach (["/admin/orders/{$order->public_id}", '/admin/orders', "/admin/bookings/{$booking}", '/admin/bookings'] as $url) {
        $res = ($this->as)($staff)->get($url);
        $res->assertOk();
        expect(($this->leaks)($res->getContent()))->toBe([], "{$url} leaked");
    }
});

it('shows the full phone on the order page to staff who hold the personal-data right', function () {
    [, $order] = ($this->swept)();
    $order = DB::table('orders')->where('id', $order->id)->first();
    $pii = catalogStaff(['orders.view', 'customers.view_pii']);

    ($this->as)($pii)->get("/admin/orders/{$order->public_id}")->assertOk()->assertSee($order->contact_phone);
});

it('shows no full phone or email on any admin page that opens without picking a record', function () {
    ($this->swept)();
    $staff = ($this->everyoneButPii)();

    $leaks = [];
    $visited = 0;
    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'admin') || ! in_array('GET', $route->methods(), true) || str_contains($route->uri(), '{') || ! in_array('permission:', array_map(fn ($m) => is_string($m) ? substr($m, 0, 11) : '', $route->gatherMiddleware()), true)) {
            continue;
        }
        auth()->forgetGuards();
        $res = ($this->as)($staff)->get('/'.$route->uri());
        $visited++;
        if ($res->getStatusCode() >= 500) {
            $leaks[] = '/'.$route->uri().' answered '.$res->getStatusCode();

            continue;
        }
        $content = $res instanceof StreamedResponse ? (string) $res->streamedContent() : (string) $res->getContent();
        foreach (($this->leaks)($content) as $needle) {
            $leaks[] = '/'.$route->uri()." shows {$needle}";
        }
    }

    expect($visited)->toBeGreaterThan(40)->and($leaks)->toBe([]);
});

it('shows the email on the booking page to staff who hold the personal-data right', function () {
    [$booking] = ($this->swept)();
    $pii = catalogStaff(['bookings.view', 'customers.view_pii']);

    ($this->as)($pii)->get("/admin/bookings/{$booking}")->assertOk()->assertSee(SWEEP_EMAIL);
});

it('shows no full phone or email on payments, reports, the audit log and the global search', function () {
    ($this->swept)();
    $staff = ($this->everyoneButPii)();
    $payment = DB::table('payments')->value('public_id');
    $period = '?from=2026-11-01&to=2026-11-30';   // the booked order was placed on 30 November; a longer period is refused

    $urls = ['/admin/audit', '/admin/audit/export', '/admin/search?q=01712345678', '/admin/search?q=VM-', '/admin/payments', '/admin/payments/cod', '/admin/payments/refunds', '/admin/payments/reconciliation'];
    if ($payment !== null) {
        $urls[] = "/admin/payments/{$payment}";
    }
    foreach (app(ReportCatalogue::class)->all() as $report) {
        $urls[] = "/admin/reports/{$report->code()}{$period}";
    }

    $leaks = [];
    $number = DB::table('orders')->value('order_number');
    $filePage = ($this->as)($staff)->get('/admin/reports/orders_file'.$period);
    expect($filePage->getStatusCode())->toBe(200)->and($filePage->getContent())->toContain($number);   // the page really lists the order, so the masking below is looked at, not skipped
    foreach ($urls as $url) {
        auth()->forgetGuards();
        $res = ($this->as)($staff)->get($url);
        if ($res->getStatusCode() >= 500) {
            $leaks[] = "{$url} answered ".$res->getStatusCode();

            continue;
        }
        $content = $res instanceof StreamedResponse ? (string) $res->streamedContent() : (string) $res->getContent();
        $typed = str_contains($url, 'q=') ? substr($url, strpos($url, 'q=') + 2) : '';   // a search page repeats what the person typed; that is their own input, not stored data
        foreach (($this->leaks)($typed === '' ? $content : str_replace($typed, '', $content)) as $needle) {
            $leaks[] = "{$url} shows {$needle}";
        }
    }

    expect($urls)->toContain('/admin/reports/orders_file'.$period)->and($leaks)->toBe([]);
});
