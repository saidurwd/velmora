<?php

declare(strict_types=1);

use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Database\Seeders\PaymentMethodSeeder;
use App\Modules\Payments\DTOs\Capabilities;
use App\Modules\Payments\DTOs\PaymentIntent;
use App\Modules\Payments\DTOs\RefundRequest;
use App\Modules\Payments\DTOs\VerificationRequest;
use App\Modules\Payments\DTOs\VerifiedPayment;
use App\Modules\Payments\Enums\VerifiedStatus;
use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Gateways\FakeGateway;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentMethod;
use App\Modules\Payments\Services\GatewayRegistry;
use App\Modules\Payments\Support\ProviderAmount;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Money;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->intent = fn (string $ref = 'VM-2610-000123-1') => new PaymentIntent($ref, Money::of(150000), 'VM-2610-000123', 'Rahim', '+8801712345678', null, 'House 5', 'Dhaka', '1205', 2, true, 'Cotton Tee x2', 'https://shop.test/ok', 'https://shop.test/fail', 'https://shop.test/cancel', 'https://shop.test/ipn', ['payment' => 'p1']);
});

describe('the amount between provider and shop', function () {
    it('turns provider text into exact paisa and back', function () {
        expect(ProviderAmount::toMoney('100')->amountMinor)->toBe(10000)->and(ProviderAmount::toMoney('100.00')->amountMinor)->toBe(10000)->and(ProviderAmount::toMoney('55.4')->amountMinor)->toBe(5540)
            ->and(ProviderAmount::toMoney(' 0.05 ')->amountMinor)->toBe(5)->and(ProviderAmount::toDecimal(Money::of(5540)))->toBe('55.40')->and(ProviderAmount::toDecimal(Money::of(10000)))->toBe('100.00');
    });

    it('refuses anything that is not an exact amount instead of rounding it', function () {
        foreach (['', 'abc', '10.123', '-5', '1e3', '১০০', '10,00', '10.', '.5', '100 BDT'] as $bad) {
            expect(fn () => ProviderAmount::toMoney($bad), "[{$bad}]")->toThrow(PaymentException::class, 'provider_amount_invalid');
        }
    });

    it('never loses a paisa across 10,000 round trips', function () {
        mt_srand(7);
        foreach (range(1, 10000) as $_) {
            $minor = mt_rand(1, 50_000_000);
            expect(ProviderAmount::toMoney(ProviderAmount::toDecimal(Money::of($minor)))->amountMinor)->toBe($minor);
        }
    });
});

describe('the registry', function () {
    it('finds a provider by name, builds it once, and refuses an unknown one', function () {
        $registry = new GatewayRegistry;
        $built = 0;
        $registry->register('fake', function () use (&$built) {
            $built++;

            return new FakeGateway;
        });

        expect($registry->has('fake'))->toBeTrue()->and($registry->has('nope'))->toBeFalse()->and($registry->providers())->toBe(['fake'])
            ->and($registry->for('fake'))->toBeInstanceOf(PaymentGateway::class)->and($registry->for('fake'))->toBe($registry->for('fake'))->and($built)->toBe(1);
        expect(fn () => $registry->for('nope'))->toThrow(PaymentException::class, 'unknown_provider');
    });

    it('has the fake provider here (tests and local work) and it is built by the container', function () {
        expect(app(GatewayRegistry::class)->has('fake'))->toBeTrue()->and(app(GatewayRegistry::class)->for('fake'))->toBe(app(FakeGateway::class));
    });
});

describe('the fake gateway (what every later test plays against)', function () {
    it('starts a payment and says where to send the customer, without any network', function () {
        Http::fake();
        $gateway = new FakeGateway;

        $result = $gateway->initiate(($this->intent)());

        expect($result->redirectUrl)->toBe('https://fake-gateway.test/pay/VM-2610-000123-1')->and($result->sessionKey)->toBe('FAKE-VM-2610-000123-1')->and($gateway->initiated)->toHaveCount(1);
        Http::assertNothingSent();
    });

    it('says what it is told to say, and "unknown" about everything else', function () {
        $gateway = new FakeGateway;
        $paid = new VerifiedPayment(VerifiedStatus::Valid, 'VM-1-1', Money::of(150000), 'BANK-1', 'VAL-1');
        $gateway->answer('VM-1-1', $paid);

        expect($gateway->verify(new VerificationRequest('VM-1-1', 'VAL-1')))->toBe($paid)->and($gateway->verify(new VerificationRequest('VM-9-9'))->status)->toBe(VerifiedStatus::Unknown)
            ->and($gateway->status('VM-1-1'))->toBe($paid)->and($gateway->verifications)->toHaveCount(2);
    });

    it('reads a notification into the fields needed to find the payment, trusting none of it', function () {
        $gateway = new FakeGateway;
        $request = Request::create('/ipn', 'POST', ['tran_id' => 'VM-1-1', 'status' => 'VALID', 'val_id' => 'VAL-1', 'amount' => '1500.00', 'store_passwd' => 'secret']);

        $event = $gateway->parseCallback($request);

        expect($event->merchantRef)->toBe('VM-1-1')->and($event->claimedStatus)->toBe('VALID')->and($event->providerValidationId)->toBe('VAL-1')->and($event->providerEventId)->toHaveLength(64)
            ->and($event->payload)->not->toHaveKey('store_passwd')
            ->and($gateway->parseCallback($request)->providerEventId)->toBe($event->providerEventId);   // the same notification has the same id
        expect(fn () => $gateway->parseCallback(Request::create('/ipn', 'POST', ['status' => 'VALID'])))->toThrow(PaymentException::class, 'callback_unreadable');
    });

    it('can be made to fail once, to rehearse a provider outage', function () {
        $gateway = new FakeGateway;
        $gateway->failNext('verify');

        expect(fn () => $gateway->verify(new VerificationRequest('X')))->toThrow(PaymentException::class, 'provider_unavailable');
        expect($gateway->verify(new VerificationRequest('X'))->status)->toBe(VerifiedStatus::Unknown);   // only once
    });

    it('refunds when the provider can, and refuses honestly when it cannot', function () {
        $can = new FakeGateway;
        $result = $can->refund(new RefundRequest('BANK-1', 'RF-1', Money::of(5000), 'out of stock'));
        expect($result->state)->toBe('accepted')->and($result->providerRefundId)->toBe('FAKEREF-RF-1')->and($can->refundStatus('FAKEREF-RF-1')->state)->toBe('refunded')
            ->and($can->refundWillBe('FAKEREF-RF-1', 'processing')->refundStatus('FAKEREF-RF-1')->state)->toBe('processing');

        $cannot = new FakeGateway(new Capabilities);
        expect(fn () => $cannot->refund(new RefundRequest('BANK-1', 'RF-2', Money::of(5000), 'x')))->toThrow(PaymentException::class, 'refund_not_supported')
            ->and($cannot->capabilities()->refund)->toBeFalse()->and($cannot->capabilities()->partialRefund)->toBeFalse();
    });

    it('declares nothing it was not given: a plain Capabilities is "can do nothing"', function () {
        $none = new Capabilities;

        expect([$none->refund, $none->partialRefund, $none->refundStatus, $none->statusQuery, $none->signedCallback, $none->sandbox])->each->toBeFalse();
    });
});

describe('payment methods on a fresh install', function () {
    beforeEach(fn () => $this->seed(LanguageSeeder::class));

    it('seeds cash on delivery on and online payment off, with labels in both languages', function () {
        $this->seed(PaymentMethodSeeder::class);

        $methods = PaymentMethod::query()->with('translations')->orderBy('sort_order')->get();
        expect($methods->pluck('code')->all())->toBe(['cod', 'online'])->and($methods[0]->is_enabled)->toBeTrue()->and($methods[1]->is_enabled)->toBeFalse()->and($methods[1]->provider)->toBe('sslcommerz')
            ->and($methods[1]->min_minor)->toBe(1000)->and($methods[1]->max_minor)->toBe(50_000_000)
            ->and($methods[0]->translate('label', 'bn'))->toBe('ক্যাশ অন ডেলিভারি')->and($methods[1]->translate('label', 'en'))->toBe('Pay online');
    });

    it('never overwrites what the owner changed, however often it runs', function () {
        $this->seed(PaymentMethodSeeder::class);
        DB::table('payment_methods')->where('code', 'online')->update(['is_enabled' => true, 'min_minor' => 5000]);
        DB::table('payment_method_translations')->where('label', 'Pay online')->update(['label' => 'Card or bKash']);

        $this->seed(PaymentMethodSeeder::class);

        $online = PaymentMethod::query()->with('translations')->where('code', 'online')->firstOrFail();
        expect($online->is_enabled)->toBeTrue()->and($online->min_minor)->toBe(5000)->and($online->translate('label', 'en'))->toBe('Card or bKash')
            ->and(PaymentMethod::count())->toBe(2);
    });

    it('refuses limits that make no sense', function () {
        $insert = fn (array $over) => DB::table('payment_methods')->insert($over + ['code' => 'x'.random_int(1, 99999), 'provider' => 'fake', 'type' => 'gateway', 'created_at' => now(), 'updated_at' => now()]);

        expect(fn () => $insert(['min_minor' => 500, 'max_minor' => 100]))->toThrow(QueryException::class)
            ->and(fn () => $insert(['min_minor' => -1]))->toThrow(QueryException::class)
            ->and(fn () => $insert(['type' => 'cheque']))->toThrow(QueryException::class);
    });
});

it('computes what is left to refund from the payment row', function () {
    $p = new Payment(['captured_minor' => 100000, 'refunded_minor' => 30000]);

    expect($p->refundableMinor())->toBe(70000)->and((new Payment(['captured_minor' => 0, 'refunded_minor' => 0]))->refundableMinor())->toBe(0);
});
