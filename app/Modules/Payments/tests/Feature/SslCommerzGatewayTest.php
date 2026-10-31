<?php

declare(strict_types=1);

use App\Modules\Payments\DTOs\PaymentIntent;
use App\Modules\Payments\DTOs\RefundRequest;
use App\Modules\Payments\DTOs\VerificationRequest;
use App\Modules\Payments\Enums\VerifiedStatus;
use App\Modules\Payments\Exceptions\PaymentException;
use App\Modules\Payments\Gateways\SslCommerzGateway;
use App\Modules\Payments\Providers\PaymentsServiceProvider;
use App\Modules\Payments\Services\GatewayRegistry;
use App\Modules\Platform\DTOs\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

const SSL_STORE = 'velmorashop@ssl';
const SSL_PASS = 'S3cretStorePassw0rd';
const SSL_REF = 'VM-2610-000123-1';

function sslFixture(string $name, array $over = []): array
{
    return array_replace(json_decode((string) file_get_contents(__DIR__.'/../Fixtures/sslcommerz/'.$name.'.json'), true, flags: JSON_THROW_ON_ERROR), $over);
}

beforeEach(function () {
    $this->gateway = new SslCommerzGateway(SSL_STORE, SSL_PASS, true, 'shop@example.com');
    $this->intent = fn (array $over = []) => new PaymentIntent(...array_replace([
        'merchantRef' => SSL_REF, 'amount' => Money::of(150000), 'orderNumber' => 'VM-2610-000123', 'customerName' => 'Rahim Uddin', 'customerPhone' => '+8801712345678', 'customerEmail' => 'rahim@example.com',
        'addressLine' => 'House 5, Road 2', 'city' => 'Dhaka', 'postcode' => '1205', 'itemCount' => 2, 'needsShipping' => true, 'productSummary' => 'Cotton Tee ×2',
        'successUrl' => 'https://shop.test/en/pay/return/p1/success?expires=1&signature=a', 'failUrl' => 'https://shop.test/en/pay/return/p1/fail?expires=1&signature=b', 'cancelUrl' => 'https://shop.test/en/pay/return/p1/cancel?expires=1&signature=c',
        'notifyUrl' => 'https://shop.test/api/v1/webhooks/payments/sslcommerz', 'meta' => ['payment' => '01paymentpublicid'],
    ], $over));

    /** Answers by path (the first route whose text is in the URL). A route may be an array (JSON), a status code, or a closure that throws. */
    $this->api = function (array $routes) {
        $this->app->forgetInstance(HttpFactory::class);   // a fresh client each time: stubs added with fake() stack, and the first would keep winning
        Http::clearResolvedInstance(HttpFactory::class);
        Http::fake(function (HttpRequest $request) use ($routes) {
            foreach ($routes as $path => $answer) {
                if (str_contains($request->url(), $path)) {
                    return $answer instanceof Closure ? $answer() : (is_int($answer) ? Http::response('', $answer) : (is_string($answer) ? Http::response($answer, 200) : Http::response($answer, 200)));
                }
            }

            return Http::response('unexpected call to '.$request->url(), 500);
        });
    };
    $this->sent = fn () => Http::recorded()->map(fn ($pair) => $pair[0]);
    $this->logs = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) {
        $this->logs[] = $e->message.' '.json_encode($e->context);
    });
});

describe('starting a payment', function () {
    it('asks SSLCommerz for a session with exactly the documented fields, and returns where to send the customer', function () {
        ($this->api)(['/gwprocess/v4/api.php' => sslFixture('initiation_success')]);

        $result = $this->gateway->initiate(($this->intent)());

        expect($result->redirectUrl)->toBe('https://sandbox.sslcommerz.com/gwprocess/v4/gw.php?Q=PAY&SESSIONKEY=F298BC45B0688E02768900C4F6B28C8B')->and($result->sessionKey)->toBe('F298BC45B0688E02768900C4F6B28C8B')
            ->and($result->summary)->toBe(['gateway' => 'sslcommerz', 'status' => 'SUCCESS']);

        $request = ($this->sent)()->first();
        expect($request->method())->toBe('POST')->and($request->url())->toBe('https://sandbox.sslcommerz.com/gwprocess/v4/api.php')->and($request->isForm())->toBeTrue();
        expect($request->data())->toBe([
            'store_id' => SSL_STORE, 'store_passwd' => SSL_PASS, 'total_amount' => '1500.00', 'currency' => 'BDT', 'tran_id' => SSL_REF,
            'success_url' => 'https://shop.test/en/pay/return/p1/success?expires=1&signature=a', 'fail_url' => 'https://shop.test/en/pay/return/p1/fail?expires=1&signature=b',
            'cancel_url' => 'https://shop.test/en/pay/return/p1/cancel?expires=1&signature=c', 'ipn_url' => 'https://shop.test/api/v1/webhooks/payments/sslcommerz',
            'cus_name' => 'Rahim Uddin', 'cus_email' => 'rahim@example.com', 'cus_add1' => 'House 5, Road 2', 'cus_city' => 'Dhaka', 'cus_postcode' => '1205', 'cus_country' => 'Bangladesh', 'cus_phone' => '+8801712345678',
            'shipping_method' => 'YES', 'num_of_item' => '2', 'product_name' => 'Cotton Tee ×2', 'product_category' => 'goods', 'product_profile' => 'physical-goods', 'value_a' => '01paymentpublicid',
            'ship_name' => 'Rahim Uddin', 'ship_add1' => 'House 5, Road 2', 'ship_city' => 'Dhaka', 'ship_postcode' => '1205', 'ship_country' => 'Bangladesh',
        ]);
    });

    it('sends a download as non-physical goods with no shipping address, and a stand-in email when the customer gave none', function () {
        ($this->api)(['/gwprocess/v4/api.php' => sslFixture('initiation_success')]);

        $this->gateway->initiate(($this->intent)(['needsShipping' => false, 'customerEmail' => null, 'postcode' => null]));

        $data = ($this->sent)()->first()->data();
        expect($data['shipping_method'])->toBe('NO')->and($data['product_profile'])->toBe('non-physical-goods')->and($data['product_category'])->toBe('digital')->and($data['cus_email'])->toBe('shop@example.com')
            ->and($data['cus_postcode'])->toBe('0000')->and($data)->not->toHaveKeys(['ship_name', 'ship_add1', 'ship_city', 'ship_postcode', 'ship_country']);
    });

    it('uses the live address only when it is not in sandbox mode', function () {
        ($this->api)(['/gwprocess/v4/api.php' => sslFixture('initiation_success', ['GatewayPageURL' => 'https://securepay.sslcommerz.com/gwprocess/v4/gw.php?Q=PAY&SESSIONKEY=X'])]);

        (new SslCommerzGateway(SSL_STORE, SSL_PASS, false))->initiate(($this->intent)());

        expect(($this->sent)()->first()->url())->toBe('https://securepay.sslcommerz.com/gwprocess/v4/api.php');
    });

    it('refuses to send the customer anywhere but SSLCommerz\'s own domain, whatever the answer says', function () {
        foreach (['https://evil.example.com/pay', 'http://sandbox.sslcommerz.com/gw.php', 'https://sslcommerz.com.evil.example/gw.php', 'javascript:alert(1)', '//evil.example.com'] as $bad) {
            ($this->api)(['/gwprocess/v4/api.php' => sslFixture('initiation_success', ['GatewayPageURL' => $bad])]);

            expect(fn () => $this->gateway->initiate(($this->intent)()), $bad)->toThrow(PaymentException::class, 'provider_bad_response');
        }
    });

    it('treats a refusal, a missing page address and an unreadable answer as failures to start', function () {
        ($this->api)(['/gwprocess/v4/api.php' => ['status' => 'FAILED', 'failedreason' => 'Store Credential Error Or Store is De-active', 'sessionkey' => '', 'GatewayPageURL' => '']]);
        expect(fn () => $this->gateway->initiate(($this->intent)()))->toThrow(PaymentException::class, 'provider_rejected');

        ($this->api)(['/gwprocess/v4/api.php' => sslFixture('initiation_success', ['GatewayPageURL' => ''])]);
        expect(fn () => $this->gateway->initiate(($this->intent)()))->toThrow(PaymentException::class, 'provider_rejected');

        ($this->api)(['/gwprocess/v4/api.php' => '<html>maintenance</html>']);
        expect(fn () => $this->gateway->initiate(($this->intent)()))->toThrow(PaymentException::class, 'provider_bad_response');
    });

    it('refuses what SSLCommerz would refuse, before calling it', function () {
        ($this->api)([]);

        expect(fn () => $this->gateway->initiate(($this->intent)(['amount' => Money::of(999)])))->toThrow(PaymentException::class, 'amount_out_of_range')
            ->and(fn () => $this->gateway->initiate(($this->intent)(['amount' => Money::of(50_000_001)])))->toThrow(PaymentException::class, 'amount_out_of_range')
            ->and(fn () => $this->gateway->initiate(($this->intent)(['amount' => Money::of(150000, 'USD')])))->toThrow(PaymentException::class, 'currency_unsupported')
            ->and(fn () => $this->gateway->initiate(($this->intent)(['merchantRef' => str_repeat('A', 31)])))->toThrow(PaymentException::class, 'reference_too_long')
            ->and(fn () => $this->gateway->initiate(($this->intent)(['successUrl' => 'https://shop.test/'.str_repeat('a', 250)])))->toThrow(PaymentException::class, 'callback_url_too_long');
        expect(($this->sent)())->toHaveCount(0);
    });

    it('accepts the largest and smallest amounts it allows, written with two decimals', function () {
        ($this->api)(['/gwprocess/v4/api.php' => sslFixture('initiation_success')]);

        $this->gateway->initiate(($this->intent)(['amount' => Money::of(1000)]));
        $this->gateway->initiate(($this->intent)(['amount' => Money::of(50_000_000)]));

        expect(($this->sent)()->map(fn ($r) => $r->data()['total_amount'])->all())->toBe(['10.00', '500000.00']);
    });
});

describe('asking about a payment by its validation id (Order Validation API)', function () {
    it('reads a successful payment exactly, in paisa, and keeps the bank transaction id for refunds', function () {
        ($this->api)(['validationserverAPI.php' => sslFixture('validation_validated', ['tran_id' => SSL_REF, 'amount' => '1500.00', 'currency_amount' => '1500.00'])]);

        $v = $this->gateway->verify(new VerificationRequest(SSL_REF, '1709162025351ElIuHtUtFReBwE'));

        expect($v->status)->toBe(VerifiedStatus::Valid)->and($v->merchantRef)->toBe(SSL_REF)->and($v->amount?->amountMinor)->toBe(150000)->and($v->amount?->currency)->toBe('BDT')
            ->and($v->providerTxnId)->toBe('1709162025350IvUOK8nCTb6Uan')->and($v->providerValidationId)->toBe('1709162025351ElIuHtUtFReBwE')->and($v->riskFlag)->toBeFalse();

        $call = ($this->sent)()->first();
        expect($call->method())->toBe('GET')->and($call->url())->toStartWith('https://sandbox.sslcommerz.com/validator/api/validationserverAPI.php?')
            ->and($call->data())->toBe(['val_id' => '1709162025351ElIuHtUtFReBwE', 'store_id' => SSL_STORE, 'store_passwd' => SSL_PASS, 'format' => 'json']);
    });

    it('treats "VALID" and "VALIDATED" (already validated before) the same, and never keeps card numbers or secrets in the stored answer', function () {
        foreach (['VALID', 'VALIDATED'] as $status) {
            ($this->api)(['validationserverAPI.php' => sslFixture('validation_validated', ['tran_id' => SSL_REF, 'status' => $status, 'amount' => '1500.00', 'currency_amount' => '1500.00'])]);

            $v = $this->gateway->verify(new VerificationRequest(SSL_REF, 'VAL1'));

            expect($v->status)->toBe(VerifiedStatus::Valid);
            expect(json_encode($v->raw))->not->toContain('455445XXXXXX4326')->not->toContain(SSL_PASS)->and($v->raw)->not->toHaveKey('card_no');
        }
    });

    it('says "unknown" for a validation id SSLCommerz does not recognise', function () {
        ($this->api)([
            'validationserverAPI.php' => ['status' => 'INVALID_TRANSACTION', 'APIConnect' => 'FAILED'],
            'merchantTransIDvalidationAPI.php' => ['APIConnect' => 'DONE', 'no_of_trans_found' => 0, 'element' => []],
        ]);

        $v = $this->gateway->verify(new VerificationRequest(SSL_REF, 'FORGED-VALIDATION-ID'));

        expect($v->status)->toBe(VerifiedStatus::Unknown)->and($v->amount)->toBeNull();
    });

    it('does not believe a "VALID" that is not marked as a completed API call', function () {
        ($this->api)(['validationserverAPI.php' => sslFixture('validation_validated', ['tran_id' => SSL_REF, 'APIConnect' => 'FAILED'])]);

        expect(fn () => $this->gateway->verify(new VerificationRequest(SSL_REF, 'VAL1')))->toThrow(PaymentException::class, 'provider_bad_response');
    });

    it('drops a validation id that belongs to another transaction and asks about OUR reference instead', function () {
        ($this->api)([
            'validationserverAPI.php' => sslFixture('validation_validated', ['tran_id' => 'VM-2610-000999-1']),   // a real payment, but somebody else's
            'merchantTransIDvalidationAPI.php' => ['APIConnect' => 'DONE', 'no_of_trans_found' => 0, 'element' => []],
        ]);

        $v = $this->gateway->verify(new VerificationRequest(SSL_REF, 'OTHER-ORDERS-VAL-ID'));

        expect($v->status)->toBe(VerifiedStatus::Unknown)->and($v->merchantRef)->toBe(SSL_REF);
        expect(($this->sent)()->map(fn ($r) => parse_url($r->url(), PHP_URL_PATH))->all())->toBe(['/validator/api/validationserverAPI.php', '/validator/api/merchantTransIDvalidationAPI.php']);
    });

    it('ignores a validation id that is not even shaped like one, and goes straight to our own reference', function () {
        ($this->api)(['merchantTransIDvalidationAPI.php' => ['APIConnect' => 'DONE', 'no_of_trans_found' => 0, 'element' => []]]);

        foreach (['../../etc/passwd', 'a b', str_repeat('x', 51), "x\ny", 'x&store_id=evil'] as $hint) {
            $this->gateway->verify(new VerificationRequest(SSL_REF, $hint));
        }

        expect(($this->sent)()->every(fn ($r) => str_contains($r->url(), 'merchantTransIDvalidationAPI.php')))->toBeTrue();
    });

    it('flags a high-risk payment', function () {
        ($this->api)(['validationserverAPI.php' => sslFixture('validation_validated', ['tran_id' => SSL_REF, 'amount' => '1500.00', 'currency_amount' => '1500.00', 'risk_level' => '1', 'risk_title' => 'Risky'])]);

        expect($this->gateway->verify(new VerificationRequest(SSL_REF, 'VAL1'))->riskFlag)->toBeTrue();
    });
});

describe('amounts (never rounded, never guessed)', function () {
    beforeEach(function () {
        $this->answer = function (array $over) {
            ($this->api)(['validationserverAPI.php' => sslFixture('validation_validated', $over + ['tran_id' => SSL_REF])]);

            return $this->gateway->verify(new VerificationRequest(SSL_REF, 'VAL1'));
        };
    });

    it('reads whole numbers and one or two decimals exactly', function () {
        foreach ([['1500', 150000], ['1500.0', 150000], ['1500.50', 150050], ['0.05', 5], ['500000.00', 50_000_000]] as [$text, $minor]) {
            expect(($this->answer)(['amount' => $text, 'currency_amount' => $text])->amount?->amountMinor, $text)->toBe($minor);
        }
    });

    it('returns no amount when the two BDT amount fields disagree, so the payment is held for a person', function () {
        $v = ($this->answer)(['amount' => '1500.00', 'currency_amount' => '1499.00']);

        expect($v->status)->toBe(VerifiedStatus::Valid)->and($v->amount)->toBeNull();
    });

    it('returns no amount for text that is not an exact amount', function () {
        foreach (['1e3', '15,00', '১৫০০', '15.005', '-5', 'abc'] as $text) {
            expect(($this->answer)(['amount' => $text, 'currency_amount' => $text])->amount, $text)->toBeNull();
        }
    });

    it('reports the currency the customer actually paid in, so a different currency is a visible mismatch', function () {
        $v = ($this->answer)(['currency_type' => 'USD', 'currency_amount' => '15.00', 'amount' => '1500.00']);

        expect($v->amount?->currency)->toBe('USD')->and($v->amount?->amountMinor)->toBe(1500);
    });
});

describe('asking about a payment by our reference (Transaction Query API)', function () {
    it('prefers a successful payment among several results for the same reference, and counts them', function () {
        ($this->api)(['merchantTransIDvalidationAPI.php' => sslFixture('query_by_tran_id')]);

        $v = $this->gateway->status('59C2A4F6432F8');

        expect($v->status)->toBe(VerifiedStatus::Valid)->and($v->providerTxnId)->toBe('1709202337441AbCdEfGhIjKlM')->and($v->amount?->amountMinor)->toBe(1000)
            ->and($v->raw['valid_count'])->toBe(1)->and($v->raw['no_of_trans_found'])->toBe(2);
        $call = ($this->sent)()->first();
        expect($call->url())->toStartWith('https://sandbox.sslcommerz.com/validator/api/merchantTransIDvalidationAPI.php?')->and($call->data())->toBe(['tran_id' => '59C2A4F6432F8', 'store_id' => SSL_STORE, 'store_passwd' => SSL_PASS, 'format' => 'json']);
    });

    it('counts every successful payment under one reference, so extra money can be given back', function () {
        $fixture = sslFixture('query_by_tran_id');
        $second = $fixture['element'][1];
        $second['val_id'] = 'ANOTHER-VAL';
        $second['bank_tran_id'] = 'ANOTHER-BANK-ID';
        $fixture['element'][] = $second;
        ($this->api)(['merchantTransIDvalidationAPI.php' => $fixture]);

        expect($this->gateway->status('59C2A4F6432F8')->raw['valid_count'])->toBe(2);
    });

    it('says "pending" while waiting and "failed" when only failures exist', function () {
        $fixture = sslFixture('query_by_tran_id');
        $pending = $fixture;
        $pending['element'] = [array_replace($fixture['element'][0], ['status' => 'PENDING'])];
        ($this->api)(['merchantTransIDvalidationAPI.php' => $pending]);
        expect($this->gateway->status('59C2A4F6432F8')->status)->toBe(VerifiedStatus::Pending);

        $failed = $fixture;
        $failed['element'] = [$fixture['element'][0]];
        ($this->api)(['merchantTransIDvalidationAPI.php' => $failed]);
        expect($this->gateway->status('59C2A4F6432F8')->status)->toBe(VerifiedStatus::Failed);
    });

    it('ignores results that are about some other reference', function () {
        ($this->api)(['merchantTransIDvalidationAPI.php' => sslFixture('query_by_tran_id')]);

        $v = $this->gateway->status(SSL_REF);

        expect($v->status)->toBe(VerifiedStatus::Unknown)->and($v->providerTxnId)->toBeNull();
    });

    it('says "unknown" when SSLCommerz has nothing for the reference', function () {
        ($this->api)(['merchantTransIDvalidationAPI.php' => ['APIConnect' => 'DONE', 'no_of_trans_found' => 0, 'element' => []]]);

        expect($this->gateway->status(SSL_REF)->status)->toBe(VerifiedStatus::Unknown);
    });

    it('refuses to conclude anything when the shop\'s account is not accepted, or is inactive', function () {
        foreach (['FAILED' => 'provider_auth_failed', 'INACTIVE' => 'provider_inactive', 'INVALID_REQUEST' => 'provider_invalid_request', 'SOMETHING' => 'provider_bad_response'] as $connect => $reason) {
            ($this->api)(['merchantTransIDvalidationAPI.php' => ['APIConnect' => $connect, 'no_of_trans_found' => 0]]);

            expect(fn () => $this->gateway->status(SSL_REF), $connect)->toThrow(PaymentException::class, $reason);
        }
    });
});

describe('refunds', function () {
    it('asks for a refund with the documented fields and our unique refund id', function () {
        ($this->api)(['merchantTransIDvalidationAPI.php' => sslFixture('refund_success')]);

        $r = $this->gateway->refund(new RefundRequest('1709162345070ANJdZV8LyI4cMw', 'RF-2610-000001', Money::of(550), 'Out of stock', 'VM-2610-000123'));

        expect($r->state)->toBe('accepted')->and($r->providerRefundId)->toBe('59bd63fea5455');
        $call = ($this->sent)()->first();
        expect($call->method())->toBe('GET')->and($call->data())->toBe([
            'bank_tran_id' => '1709162345070ANJdZV8LyI4cMw', 'refund_trans_id' => 'RF-2610-000001', 'refund_amount' => '5.50', 'refund_remarks' => 'Out of stock', 'refe_id' => 'VM-2610-000123',
            'store_id' => SSL_STORE, 'store_passwd' => SSL_PASS, 'format' => 'json',
        ]);
    });

    it('maps "processing" (already under way) and "failed" faithfully', function () {
        ($this->api)(['merchantTransIDvalidationAPI.php' => sslFixture('refund_success', ['status' => 'processing'])]);
        $processing = $this->gateway->refund(new RefundRequest('B1', 'RF-1', Money::of(1000), 'x'));
        expect($processing->state)->toBe('processing')->and($processing->providerRefundId)->toBe('59bd63fea5455');

        ($this->api)(['merchantTransIDvalidationAPI.php' => sslFixture('refund_success', ['status' => 'failed', 'refund_ref_id' => '', 'errorReason' => 'Refund amount exceeds the transaction amount'])]);
        $failed = $this->gateway->refund(new RefundRequest('B1', 'RF-1', Money::of(1000), 'x'));
        expect($failed->state)->toBe('failed')->and($failed->providerRefundId)->toBeNull()->and($failed->failureReason)->toBe('Refund amount exceeds the transaction amount');
    });

    it('throws when the account is not accepted, and never reports a refund it does not know happened', function () {
        ($this->api)(['merchantTransIDvalidationAPI.php' => ['APIConnect' => 'FAILED']]);
        expect(fn () => $this->gateway->refund(new RefundRequest('B1', 'RF-1', Money::of(1000), 'x')))->toThrow(PaymentException::class, 'provider_auth_failed');

        ($this->api)(['merchantTransIDvalidationAPI.php' => ['APIConnect' => 'DONE', 'status' => 'something-new']]);
        expect($this->gateway->refund(new RefundRequest('B1', 'RF-1', Money::of(1000), 'x'))->state)->toBe('failed');
    });

    it('cuts the reason to the 255 characters allowed and refuses a refund id that is too long', function () {
        ($this->api)(['merchantTransIDvalidationAPI.php' => sslFixture('refund_success')]);

        $this->gateway->refund(new RefundRequest('B1', 'RF-1', Money::of(1000), str_repeat('é', 400)));
        expect(mb_strlen(($this->sent)()->first()->data()['refund_remarks']))->toBe(255);
        expect(fn () => $this->gateway->refund(new RefundRequest('B1', str_repeat('R', 31), Money::of(1000), 'x')))->toThrow(PaymentException::class, 'reference_too_long');
    });

    it('reads the status of a refund, and refuses an answer it does not understand', function () {
        ($this->api)(['merchantTransIDvalidationAPI.php' => sslFixture('refund_status')]);
        $s = $this->gateway->refundStatus('59bd63fea5455');
        expect($s->state)->toBe('refunded')->and($s->refundedOn)->toBe('2017-09-17 08:53:51')->and(($this->sent)()->first()->data())->toBe(['refund_ref_id' => '59bd63fea5455', 'store_id' => SSL_STORE, 'store_passwd' => SSL_PASS, 'format' => 'json']);

        foreach (['processing', 'cancelled'] as $state) {
            ($this->api)(['merchantTransIDvalidationAPI.php' => sslFixture('refund_status', ['status' => $state])]);
            expect($this->gateway->refundStatus('59bd63fea5455')->state)->toBe($state);
        }
        ($this->api)(['merchantTransIDvalidationAPI.php' => sslFixture('refund_status', ['status' => 'done-ish'])]);
        expect(fn () => $this->gateway->refundStatus('59bd63fea5455'))->toThrow(PaymentException::class, 'provider_bad_response');
    });

    it('declares only what the documentation shows: refunds yes, partial refunds not proven', function () {
        $c = $this->gateway->capabilities();

        expect([$c->refund, $c->partialRefund, $c->refundStatus, $c->statusQuery, $c->signedCallback, $c->sandbox, $c->minAmountMinor, $c->maxAmountMinor])->toBe([true, false, true, true, false, true, 1000, 50_000_000])
            ->and((new SslCommerzGateway(SSL_STORE, SSL_PASS, false))->capabilities()->sandbox)->toBeFalse();
    });
});

describe('notifications', function () {
    it('reads the documented IPN example into what is needed to find the payment, and trusts none of it', function () {
        $event = $this->gateway->parseCallback(Request::create('/ipn', 'POST', sslFixture('ipn_valid')));

        expect($event->merchantRef)->toBe('5a16c68b23783')->and($event->claimedStatus)->toBe('VALID')->and($event->providerValidationId)->toBe('1711231900331kHP17lnrr9T8Gt')->and($event->providerEventId)->toHaveLength(64)
            ->and($event->payload)->toMatchArray(['tran_id' => '5a16c68b23783', 'amount' => '100', 'card_no' => '425272XXXXXX3456', 'verify_sign' => '8070c0cefed9e629b01100d8a92afda2']);
    });

    it('gives the same notification the same id, and a different one when anything that matters differs', function () {
        $id = fn (array $over = []) => $this->gateway->parseCallback(Request::create('/ipn', 'POST', sslFixture('ipn_valid', $over)))->providerEventId;

        expect($id())->toBe($id())->and($id(['status' => 'FAILED']))->not->toBe($id())->and($id(['amount' => '999']))->not->toBe($id())->and($id(['val_id' => 'OTHER']))->not->toBe($id())->and($id(['tran_date' => '2020-01-01 00:00:00']))->not->toBe($id());
    });

    it('never keeps a credential, even one that is echoed back', function () {
        $event = $this->gateway->parseCallback(Request::create('/ipn', 'POST', sslFixture('ipn_valid', ['store_passwd' => SSL_PASS, 'password' => 'x', 'api_token' => 'y', 'client_secret' => 'z'])));

        expect(json_encode($event->payload))->not->toContain(SSL_PASS)->and($event->payload)->not->toHaveKeys(['store_passwd', 'password', 'api_token', 'client_secret']);
    });

    it('keeps odd input harmless: nested values become empty, long values are cut, only a bounded number of fields are kept', function () {
        $fields = ['tran_id' => 'T1', 'status' => 'VALID', 'nested' => ['a' => ['b' => 'c']], 'long' => str_repeat('x', 5000)];
        foreach (range(1, 200) as $i) {
            $fields["field_$i"] = 'v';
        }

        $event = $this->gateway->parseCallback(Request::create('/ipn', 'POST', $fields));

        expect($event->payload['nested'])->toBeNull()->and(mb_strlen($event->payload['long']))->toBe(500)->and(count($event->payload))->toBeLessThanOrEqual(80);
    });

    it('can be read from a validation id alone, and refuses one with neither', function () {
        expect($this->gateway->parseCallback(Request::create('/ipn', 'POST', ['val_id' => 'V1', 'status' => 'VALID']))->merchantRef)->toBeNull();
        expect(fn () => $this->gateway->parseCallback(Request::create('/ipn', 'POST', ['status' => 'VALID'])))->toThrow(PaymentException::class, 'callback_unreadable');
    });
});

describe('when SSLCommerz cannot be reached or misbehaves', function () {
    it('reports "unavailable" for a server error, never leaking anything', function () {
        ($this->api)(['api.php' => 500, 'validationserverAPI.php' => 503, 'merchantTransIDvalidationAPI.php' => 502]);

        foreach ([fn () => $this->gateway->initiate(($this->intent)()), fn () => $this->gateway->verify(new VerificationRequest(SSL_REF, 'VAL1')), fn () => $this->gateway->status(SSL_REF), fn () => $this->gateway->refund(new RefundRequest('B', 'R', Money::of(1000), 'x'))] as $call) {
            expect($call)->toThrow(PaymentException::class, 'provider_unavailable');
        }
    });

    it('never lets the store password reach the logs or an error message when the connection fails (the client\'s own message contains the full address)', function () {
        ($this->api)(['merchantTransIDvalidationAPI.php' => fn () => throw new ConnectionException('cURL error 28: Operation timed out for https://sandbox.sslcommerz.com/validator/api/merchantTransIDvalidationAPI.php?tran_id=VM-1&store_id='.SSL_STORE.'&store_passwd='.SSL_PASS)]);

        try {
            $this->gateway->status(SSL_REF);
            $this->fail('should have thrown');
        } catch (PaymentException $e) {
            expect($e->reason)->toBe('provider_unavailable')->and($e->getMessage())->not->toContain(SSL_PASS)->not->toContain(SSL_STORE)->and($e->getTraceAsString())->not->toContain(SSL_PASS);
        }

        expect(implode("\n", $this->logs))->not->toContain(SSL_PASS)->not->toContain(SSL_STORE)->toContain('payments.sslcommerz.unreachable');
    });

    it('never logs the password for a refusal, a bad status or a non-JSON answer either', function () {
        ($this->api)(['api.php' => ['status' => 'FAILED', 'failedreason' => 'Store Credential Error']]);
        try {
            $this->gateway->initiate(($this->intent)());
        } catch (PaymentException) {
        }
        ($this->api)(['merchantTransIDvalidationAPI.php' => ['APIConnect' => 'FAILED']]);
        try {
            $this->gateway->status(SSL_REF);
        } catch (PaymentException) {
        }
        ($this->api)(['merchantTransIDvalidationAPI.php' => 'not json at all']);
        try {
            $this->gateway->status(SSL_REF);
        } catch (PaymentException) {
        }

        expect(implode("\n", $this->logs))->not->toContain(SSL_PASS)->not->toContain(SSL_STORE)->not->toContain('store_passwd');
    });

    it('refuses to call anything without credentials', function () {
        ($this->api)([]);
        $none = new SslCommerzGateway('', '');

        expect(fn () => $none->status(SSL_REF))->toThrow(PaymentException::class, 'provider_not_configured')->and(($this->sent)())->toHaveCount(0);
    });
});

describe('when the gateway is available at all', function () {
    beforeEach(function () {
        $this->provider = app()->getProvider(PaymentsServiceProvider::class);
        $this->register = function (array $config, bool $production = false, bool $allowLive = false) {
            $registry = new GatewayRegistry;
            $this->provider->registerSslCommerz($registry, $config, $production, $allowLive);

            return $registry;
        };
    });

    it('needs both a store id and a password', function () {
        expect(($this->register)(['store_id' => 'x@ssl', 'store_password' => 'p', 'sandbox' => true])->has('sslcommerz'))->toBeTrue()
            ->and(($this->register)(['store_id' => '', 'store_password' => 'p', 'sandbox' => true])->has('sslcommerz'))->toBeFalse()
            ->and(($this->register)(['store_id' => 'x@ssl', 'store_password' => '', 'sandbox' => true])->has('sslcommerz'))->toBeFalse()
            ->and(($this->register)([])->has('sslcommerz'))->toBeFalse();
    });

    it('reaches the live bank only in production, with sandbox off, and with the explicit switch: all three', function () {
        $live = ['store_id' => 'real', 'store_password' => 'p', 'sandbox' => false];

        expect(($this->register)($live, production: false, allowLive: false)->has('sslcommerz'))->toBeFalse()
            ->and(($this->register)($live, production: false, allowLive: true)->has('sslcommerz'))->toBeFalse()   // a development machine holding live credentials
            ->and(($this->register)($live, production: true, allowLive: false)->has('sslcommerz'))->toBeFalse()
            ->and(($this->register)($live, production: true, allowLive: true)->has('sslcommerz'))->toBeTrue()
            ->and(($this->register)($live, production: true, allowLive: true)->for('sslcommerz')->capabilities()->sandbox)->toBeFalse();
    });

    it('is the sandbox gateway when sandbox is on, in any environment', function () {
        $gw = ($this->register)(['store_id' => 'x@ssl', 'store_password' => 'p', 'sandbox' => true], production: true)->for('sslcommerz');

        expect($gw)->toBeInstanceOf(SslCommerzGateway::class)->and($gw->capabilities()->sandbox)->toBeTrue();
    });

    it('is not available in this test environment (no credentials are set here), so "Pay online" is hidden until they are', function () {
        expect(app(GatewayRegistry::class)->has('sslcommerz'))->toBeFalse();
    });
});
