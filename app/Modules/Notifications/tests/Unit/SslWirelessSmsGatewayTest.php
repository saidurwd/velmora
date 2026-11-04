<?php

declare(strict_types=1);

use App\Modules\Notifications\Contracts\SmsGateway;
use App\Modules\Notifications\Sms\SslWirelessSmsGateway;
use App\Modules\Platform\DTOs\PhoneNumber;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

const SSLW_URL = 'https://smsplus.sslwireless.com/api/v3/send-sms';
const SSLW_TOKEN = 'TEST-TOKEN-1111-2222-3333';

function sslw(): SslWirelessSmsGateway
{
    return new SslWirelessSmsGateway('https://smsplus.sslwireless.com/api/v3', SSLW_TOKEN, 'VELMORA');
}

function sslwOk(array $info = []): array
{
    return ['status' => 'SUCCESS', 'status_code' => 200, 'error_message' => '', 'smsinfo' => [$info + ['sms_status' => 'SUCCESS', 'status_message' => 'Success', 'msisdn' => '8801712345678', 'sms_type' => 'EN', 'csms_id' => 'x', 'reference_id' => 'REF-123']]];
}

beforeEach(function () {
    $this->phone = PhoneNumber::parse('01712345678');
});

describe('the request', function () {
    it('is exactly what the documentation asks for: the token, sender id, number with country code, message and a short unique reference, as JSON', function () {
        Http::fake([SSLW_URL => Http::response(sslwOk())]);

        sslw()->send($this->phone, 'Your code is 123456', ['purpose' => 'otp']);

        Http::assertSent(function (Request $r) {
            $b = $r->data();

            return $r->url() === SSLW_URL && $r->method() === 'POST' && $r->hasHeader('Content-Type', 'application/json')
                && array_keys($b) === ['api_token', 'sid', 'msisdn', 'sms', 'csms_id'] && $b['api_token'] === SSLW_TOKEN && $b['sid'] === 'VELMORA'
                && $b['msisdn'] === '8801712345678' && $b['sms'] === 'Your code is 123456' && preg_match('/^[a-z0-9]{1,20}$/', $b['csms_id']) === 1;
        });
    });

    it('gives every message its own reference, and sends Bangla text unchanged', function () {
        Http::fake([SSLW_URL => Http::response(sslwOk())]);
        $text = 'আপনার কোড ১২৩৪৫৬';

        sslw()->send($this->phone, $text);
        sslw()->send($this->phone, $text);

        $sent = Http::recorded();
        expect($sent)->toHaveCount(2)->and($sent[0][0]['csms_id'])->not->toBe($sent[1][0]['csms_id'])->and($sent[0][0]['sms'])->toBe($text);
    });

    it('sends a long message whole, up to the limit', function () {
        Http::fake([SSLW_URL => Http::response(sslwOk())]);
        $text = str_repeat('আপনার অর্ডার প্রস্তুত। ', 40);   // well over 160 characters

        sslw()->send($this->phone, $text);

        expect(mb_strlen($text))->toBeGreaterThan(400)->and(Http::recorded()[0][0]['sms'])->toBe($text);
    });

    it('refuses an empty message and one over the 1000-character limit, without sending, and never cuts one short', function () {
        Http::fake();

        $long = sslw()->send($this->phone, str_repeat('ক', 1001));
        $empty = sslw()->send($this->phone, '');
        $edge = sslw()->send($this->phone, str_repeat('ক', 1000));

        expect($long->accepted)->toBeFalse()->and($long->error)->toBe('sslwireless:message_length')->and($empty->error)->toBe('sslwireless:message_length')->and($edge->accepted)->toBeFalse();   // 1000 is allowed, but nothing is faked to answer
        Http::assertSentCount(1);
    });

    it('does nothing when it is not configured', function () {
        Http::fake();

        $r = (new SslWirelessSmsGateway('https://smsplus.sslwireless.com/api/v3', '', ''))->send($this->phone, 'hi');

        expect($r->accepted)->toBeFalse()->and($r->error)->toBe('sslwireless:not_configured');
        Http::assertNothingSent();
    });
});

describe('the answer', function () {
    it('is accepted only when the provider says so for this number, and keeps its reference', function () {
        Http::fake([SSLW_URL => Http::response(sslwOk(['reference_id' => 'REF-987']))]);

        $r = sslw()->send($this->phone, 'hi');

        expect($r->accepted)->toBeTrue()->and($r->providerReference)->toBe('REF-987')->and($r->error)->toBeNull();
    });

    it('is refused for every failure code in the documentation, with the code and our own words, nothing else', function (int $code, string $label) {
        Http::fake([SSLW_URL => Http::response(['status' => 'FAILED', 'status_code' => $code, 'error_message' => 'text from the provider 01712345678', 'smsinfo' => []])]);

        $r = sslw()->send($this->phone, 'secret OTP 987654');

        expect($r->accepted)->toBeFalse()->and($r->error)->toBe("sslwireless:{$code}:{$label}")->and($r->error)->not->toContain('01712345678')->not->toContain('987654');
    })->with([[4001, 'unauthorized'], [4002, 'sender_id_not_permitted'], [4003, 'ip_not_whitelisted'], [4004, 'invalid_request'], [4022, 'parameter_missing'], [4023, 'duplicate_reference'],
        [4025, 'invalid_number'], [4026, 'blocked_number'], [4027, 'message_too_long'], [4029, 'too_many_requests'], [4031, 'tps_exceeded'], [4033, 'too_many_otp_requests'], [5000, 'provider_error'], [9999, 'unknown']]);

    it('is refused when the provider says FAILED even if the code reads 200', function () {
        Http::fake([SSLW_URL => Http::response(['status' => 'FAILED', 'status_code' => 200, 'smsinfo' => [['sms_status' => 'SUCCESS', 'reference_id' => 'R']]])]);

        $r = sslw()->send($this->phone, 'hi');

        expect($r->accepted)->toBeFalse()->and($r->error)->toBe('sslwireless:200:unknown');
    });

    it('is refused when the request as a whole worked but this number did not (invalid, duplicate, blocked)', function (string $status) {
        Http::fake([SSLW_URL => Http::response(sslwOk(['sms_status' => $status]))]);

        $r = sslw()->send($this->phone, 'hi');

        expect($r->accepted)->toBeFalse()->and($r->error)->toBe('sslwireless:recipient_'.strtolower($status));
    })->with(['INVALID', 'DUPLICATE', 'BLOCKED']);

    it('is refused when the answer is not what the documentation describes', function () {
        Http::fake([SSLW_URL => Http::sequence()->push('<html>oops</html>', 200)->push(['status' => 'SUCCESS', 'status_code' => 200, 'smsinfo' => []], 200)->push(['status' => 'SUCCESS', 'status_code' => 200], 200)
            ->push('Bad gateway', 502)->push(['status' => 'FAILED', 'status_code' => 4001], 401)]);

        $errors = array_map(fn () => sslw()->send($this->phone, 'hi')->error, range(1, 5));

        expect($errors)->toBe(['sslwireless:bad_response', 'sslwireless:recipient_missing', 'sslwireless:recipient_missing', 'sslwireless:http_502', 'sslwireless:http_401']);
    });

    it('is refused, not thrown, when the provider cannot be reached', function () {
        Http::fake([SSLW_URL => fn () => throw new ConnectionException('timeout talking to smsplus.sslwireless.com')]);

        $r = sslw()->send($this->phone, 'hi');

        expect($r->accepted)->toBeFalse()->and($r->error)->toBe('sslwireless:connection');
    });
});

describe('what it keeps quiet', function () {
    it('never logs the token, the number or the message, whatever happens', function () {
        Log::spy();
        Http::fake([SSLW_URL => Http::sequence()->push(sslwOk())->push(['status' => 'FAILED', 'status_code' => 4025, 'smsinfo' => []])->pushStatus(500)]);

        foreach (range(1, 3) as $_) {
            sslw()->send($this->phone, 'code 424242');
        }

        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    });

    it('does not retry by itself, so a message can never be sent twice', function () {
        Http::fake([SSLW_URL => Http::response('down', 503)]);

        sslw()->send($this->phone, 'hi');

        Http::assertSentCount(1);
    });
});

describe('the choice of provider', function () {
    it('is made by SMS_DRIVER=sslwireless, with the token and sender id from the environment settings', function () {
        config(['services.sms.driver' => 'sslwireless', 'services.sms.sslwireless' => ['base_url' => 'https://smsplus.sslwireless.com/api/v3', 'api_token' => SSLW_TOKEN, 'sid' => 'VELMORA']]);
        Http::fake([SSLW_URL => Http::response(sslwOk())]);

        $gateway = app(SmsGateway::class);

        expect($gateway)->toBeInstanceOf(SslWirelessSmsGateway::class)->and($gateway->send($this->phone, 'hi')->accepted)->toBeTrue();
        Http::assertSent(fn (Request $r) => $r['sid'] === 'VELMORA' && $r['api_token'] === SSLW_TOKEN);
    });
});
