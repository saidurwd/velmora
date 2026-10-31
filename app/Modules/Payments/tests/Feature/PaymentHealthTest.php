<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\Orders\DTOs\OrderPaymentRequest;
use App\Modules\Payments\Actions\ApproveRefund;
use App\Modules\Payments\Actions\CheckPaymentHealth;
use App\Modules\Payments\Actions\ExecuteRefund;
use App\Modules\Payments\Actions\InitiatePayment;
use App\Modules\Payments\Actions\RequestRefund;
use App\Modules\Payments\Actions\VerifyPayment;
use App\Modules\Payments\Database\Seeders\PaymentMethodSeeder;
use App\Modules\Payments\DTOs\Capabilities;
use App\Modules\Payments\DTOs\VerifiedPayment;
use App\Modules\Payments\Enums\VerifiedStatus;
use App\Modules\Payments\Gateways\FakeGateway;
use App\Modules\Payments\Services\PaymentExceptions;
use App\Modules\Payments\Services\PaymentHealth;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Enums\ActorType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

beforeEach(function () {
    $this->seed([LanguageSeeder::class, PaymentMethodSeeder::class, RolesAndPermissionsSeeder::class]);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    DB::table('payment_methods')->where('code', 'online')->update(['provider' => 'fake', 'is_enabled' => true]);
    $this->gateway = app(FakeGateway::class)->withCapabilities(new Capabilities(refund: true, partialRefund: true, refundStatus: true, statusQuery: true, sandbox: true, minAmountMinor: 1000, maxAmountMinor: 50_000_000));
    $this->a = new Actor(ActorType::Staff, catalogStaff([])->id, null, 'A');
    $this->b = new Actor(ActorType::Staff, catalogStaff([])->id, null, 'B');
    $this->finance = catalogStaff(['payments.view']);

    $this->started = function (int $id): string {
        fakeOrders([$id], ['payment_method_code' => 'online', 'subtotal_minor' => 150000, 'grand_total_minor' => 150000]);
        app(InitiatePayment::class)->handle(new OrderPaymentRequest($id, (string) DB::table('orders')->where('id', $id)->value('public_id'), sprintf('VM-TEST-%06d', $id), 150000, 'BDT', 'online', 'Rahim', '+8801712345678', null, 'House 5', 'Dhaka', '1205', 2, true, 'Tee', 'en'));

        return sprintf('VM-TEST-%06d-1', $id);
    };
    $this->paid = function (int $id): object {
        $ref = ($this->started)($id);
        $this->gateway->answer($ref, new VerifiedPayment(VerifiedStatus::Valid, $ref, Money::of(150000), 'BANK-'.$id, 'VAL-'.$id));
        app(VerifyPayment::class)->handle($ref);

        return DB::table('payments')->where('order_id', $id)->first();
    };
    $this->event = function (string $status, ?bool $verified = null, ?string $at = null) {
        static $n = 0;
        DB::table('payment_webhook_events')->insert(['provider' => 'fake', 'provider_event_id' => 'e'.(++$n), 'payload' => '{}', 'status' => $status, 'verified' => $verified, 'received_at' => $at ?? now()]);
    };
    $this->snap = fn () => app(PaymentHealth::class)->snapshot();
});

describe('the signals', function () {
    it('are all quiet on a healthy shop', function () {
        ($this->paid)(1);
        ($this->event)('processed', true);

        expect(app(PaymentHealth::class)->breached())->toBe([])->and(array_keys(($this->snap)()))->toBe(PaymentHealth::SIGNALS);
    });

    it('judge the failure rate only once there are enough messages, and at 20 percent', function () {
        foreach (range(1, 4) as $i) {
            ($this->event)('error');
        }
        expect(($this->snap)()['callback_failure_rate'])->toMatchArray(['value' => 0, 'breached' => false]);   // four messages say nothing yet

        ($this->event)('processed', true);   // five messages, four failed: 80 percent
        expect(($this->snap)()['callback_failure_rate'])->toMatchArray(['value' => 80, 'breached' => true, 'threshold' => 20]);
    });

    it('stay below the failure threshold at 19 percent and ignore old messages', function () {
        foreach (range(1, 26) as $i) {
            ($this->event)('processed', true);
        }
        foreach (range(1, 6) as $i) {
            ($this->event)('error');   // 6 of 32 = 18 percent
        }
        foreach (range(1, 30) as $i) {
            ($this->event)('error', null, now()->subHours(2)->format('Y-m-d H:i:s'));   // long ago: not counted
        }

        expect(($this->snap)()['callback_failure_rate'])->toMatchArray(['value' => 18, 'breached' => false]);
    });

    it('count a payment left open long after its time limit', function () {
        ($this->started)(1);
        expect(($this->snap)()['pending_too_long']['breached'])->toBeFalse();

        $this->travel(30 + 14)->minutes();
        expect(($this->snap)()['pending_too_long']['breached'])->toBeFalse();   // past the limit, still within the grace

        $this->travel(2)->minutes();
        expect(($this->snap)()['pending_too_long'])->toMatchArray(['value' => 1, 'breached' => true]);
    });

    it('count open amount and currency mismatches, not closed ones', function () {
        $x = app(PaymentExceptions::class);
        $x->raise('amount_mismatch', null, null, 'r1', []);
        $x->raise('currency_mismatch', null, null, 'r2', []);
        $x->raise('cod_short', null, null, 'r3', []);   // a different kind of problem
        expect(($this->snap)()['amount_mismatches'])->toMatchArray(['value' => 2, 'breached' => true]);

        DB::table('reconciliation_exceptions')->whereIn('reference', ['r1', 'r2'])->update(['status' => 'resolved']);
        expect(($this->snap)()['amount_mismatches']['breached'])->toBeFalse();
    });

    it('count a refund the gateway refused, and a refund stuck, but not an old failure', function () {
        $p = ($this->paid)(1);
        $this->gateway->refuseNextRefund();
        $r = app(RequestRefund::class)->handle($this->a, $p->public_id, 150000, 'Returned');
        app(ApproveRefund::class)->handle($this->b, $r->public_id);
        app(ExecuteRefund::class)->handle($this->b, $r->public_id);
        expect(DB::table('refunds')->value('status'))->toBe('failed')->and(($this->snap)()['refund_failures'])->toMatchArray(['value' => 1, 'breached' => true]);

        $this->travel(25)->hours();
        expect(($this->snap)()['refund_failures']['breached'])->toBeFalse();

        app(PaymentExceptions::class)->raise('refund_stuck', null, null, 'x', []);
        expect(($this->snap)()['refund_failures'])->toMatchArray(['value' => 1, 'breached' => true]);
    });

    it('count messages the payment service\'s own check rejected, from three on', function () {
        ($this->event)('processed', false);
        ($this->event)('ignored', false);
        expect(($this->snap)()['forged_notifications'])->toMatchArray(['value' => 2, 'breached' => false]);

        ($this->event)('processed', false);
        ($this->event)('processed', true);
        expect(($this->snap)()['forged_notifications'])->toMatchArray(['value' => 3, 'breached' => true]);
    });
});

describe('the scheduled check', function () {
    it('logs nothing when all is well', function () {
        Log::shouldReceive('error')->never();

        expect(app(CheckPaymentHealth::class)->handle())->toBe([]);
    });

    it('logs each breached signal once an hour and again after that', function () {
        app(PaymentExceptions::class)->raise('amount_mismatch', null, null, 'r1', []);
        Log::shouldReceive('error')->twice()->with('payments.alert', ['signal' => 'amount_mismatches', 'value' => 1, 'threshold' => 1]);

        expect(app(CheckPaymentHealth::class)->handle())->toBe(['amount_mismatches'])->and(app(CheckPaymentHealth::class)->handle())->toBe([]);

        $this->travel(61)->minutes();
        expect(app(CheckPaymentHealth::class)->handle())->toBe(['amount_mismatches']);
    });

    it('is scheduled every five minutes', function () {
        $events = collect(Schedule::events())->filter(fn ($e) => str_contains((string) $e->description, 'payments:check-health'));

        expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('*/5 * * * *');
    });
});

describe('what finance sees', function () {
    it('shows a banner on the finance pages only while something is breached, in both languages', function () {
        $this->actingAs($this->finance, 'admin')->get('/admin/payments/today')->assertOk()->assertDontSee('Needs attention now');

        app(PaymentExceptions::class)->raise('amount_mismatch', null, null, 'r1', []);
        $this->actingAs($this->finance, 'admin')->get('/admin/payments/today')->assertOk()->assertSee('Needs attention now')->assertSee('amount or currency disagrees');
        $this->actingAs($this->finance, 'admin')->get('/admin/payments/settings')->assertOk()->assertSee('Needs attention now');

        $this->finance->forceFill(['locale' => 'bn'])->save();
        $this->actingAs($this->finance, 'admin')->get('/admin/payments/today')->assertOk()->assertSee('এখনই দেখা দরকার');
    });
});
