<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Enums\OtpIssueStatus;
use App\Modules\IdentityAccess\Enums\OtpPurpose;
use App\Modules\IdentityAccess\Enums\OtpVerifyResult;
use App\Modules\IdentityAccess\Events\OtpDailyCapReached;
use App\Modules\IdentityAccess\Jobs\PurgeExpiredOtpChallenges;
use App\Modules\IdentityAccess\Otp\OtpService;
use App\Modules\Notifications\Sms\FakeSmsGateway;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\PhoneNumber;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Platform\Support\FrozenClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->clock = new FrozenClock('2026-06-01 10:00:00');
    app()->instance(Clock::class, $this->clock);
    $this->sms = app(FakeSmsGateway::class);
    $this->otp = app(OtpService::class);
    $this->phone = PhoneNumber::parse('01712345678');
});

function issueOtp(object $t, ?PhoneNumber $phone = null, ?string $ip = '203.0.113.5'): OtpIssueStatus
{
    return $t->otp->issue($phone ?? $t->phone, OtpPurpose::Login, $ip);
}

it('sends a 6-digit code by SMS and verifies it once', function () {
    expect(issueOtp($this))->toBe(OtpIssueStatus::Sent);

    $code = $this->sms->lastCode();
    expect($code)->toMatch('/^\d{6}$/')
        ->and($this->sms->sent()[0]['to'])->toBe('+8801712345678')
        ->and($this->otp->verify($this->phone, OtpPurpose::Login, $code))->toBe(OtpVerifyResult::Verified);
});

it('never stores the code in the database', function () {
    issueOtp($this);
    $code = $this->sms->lastCode();
    $row = DB::table('otp_challenges')->first();

    expect(json_encode($row))->not->toContain($code)->and($row->code_hash)->toMatch('/^[0-9a-f]{64}$/');
});

it('accepts the code typed in Bangla digits', function () {
    issueOtp($this);
    $bangla = BanglaTextHelper::toBangla($this->sms->lastCode());

    expect($this->otp->verify($this->phone, OtpPurpose::Login, $bangla))->toBe(OtpVerifyResult::Verified);
});

it('rejects a reused code', function () {
    issueOtp($this);
    $code = $this->sms->lastCode();

    $this->otp->verify($this->phone, OtpPurpose::Login, $code);

    expect($this->otp->verify($this->phone, OtpPurpose::Login, $code))->toBe(OtpVerifyResult::NoChallenge);
});

it('rejects an expired code', function () {
    issueOtp($this);
    $code = $this->sms->lastCode();
    $this->clock->advanceSeconds(301);

    expect($this->otp->verify($this->phone, OtpPurpose::Login, $code))->toBe(OtpVerifyResult::Expired);
});

it('rejects a code for another purpose or another number', function () {
    issueOtp($this);
    $code = $this->sms->lastCode();

    expect($this->otp->verify($this->phone, OtpPurpose::Reset, $code))->toBe(OtpVerifyResult::NoChallenge)
        ->and($this->otp->verify(PhoneNumber::parse('01811111111'), OtpPurpose::Login, $code))->toBe(OtpVerifyResult::NoChallenge);
});

it('locks the challenge after 5 wrong codes, even for the correct one afterwards', function () {
    issueOtp($this);
    $code = $this->sms->lastCode();
    $wrong = $code === '000000' ? '111111' : '000000';

    foreach (range(1, 5) as $i) {
        expect($this->otp->verify($this->phone, OtpPurpose::Login, $wrong))->toBe(OtpVerifyResult::Invalid);
    }

    expect($this->otp->verify($this->phone, OtpPurpose::Login, $code))->toBe(OtpVerifyResult::Locked)
        ->and(DB::table('otp_challenges')->value('attempts'))->toBe(5);
});

it('enforces a resend cooldown, then allows a new code that voids the old one', function () {
    expect(issueOtp($this))->toBe(OtpIssueStatus::Sent);
    $first = $this->sms->lastCode();

    expect(issueOtp($this))->toBe(OtpIssueStatus::CoolingDown);

    $this->clock->advanceSeconds(61);
    expect(issueOtp($this))->toBe(OtpIssueStatus::Sent);
    $second = $this->sms->lastCode();

    if ($first !== $second) {
        expect($this->otp->verify($this->phone, OtpPurpose::Login, $first))->toBe(OtpVerifyResult::Invalid);
    }

    expect($this->otp->verify($this->phone, OtpPurpose::Login, $second))->toBe(OtpVerifyResult::Verified);
});

it('limits sends per phone number to 3 per 10 minutes', function () {
    foreach (range(1, 3) as $i) {
        expect(issueOtp($this, ip: null))->toBe(OtpIssueStatus::Sent);
        $this->clock->advanceSeconds(61);
    }

    expect(issueOtp($this, ip: null))->toBe(OtpIssueStatus::TargetLimited);

    $this->clock->advanceSeconds(600);
    expect(issueOtp($this, ip: null))->toBe(OtpIssueStatus::Sent);
});

it('limits sends per IP to 10 per hour across different numbers', function () {
    foreach (range(1, 10) as $i) {
        $phone = PhoneNumber::parse('0171000'.str_pad((string) $i, 4, '0', STR_PAD_LEFT));
        expect(issueOtp($this, $phone, '198.51.100.7'))->toBe(OtpIssueStatus::Sent);
    }

    expect(issueOtp($this, PhoneNumber::parse('01719999999'), '198.51.100.7'))->toBe(OtpIssueStatus::IpLimited)
        ->and(issueOtp($this, PhoneNumber::parse('01719999999'), '198.51.100.8'))->toBe(OtpIssueStatus::Sent);
});

it('stops all sending at the global daily cap and raises one alert (SMS-pumping guard)', function () {
    Event::fake([OtpDailyCapReached::class]);
    config(['otp.daily_cap' => 3]);

    foreach (range(1, 3) as $i) {
        issueOtp($this, PhoneNumber::parse('0172000000'.$i), null);
    }

    expect(issueOtp($this, PhoneNumber::parse('01721111111'), null))->toBe(OtpIssueStatus::DailyCapReached)
        ->and(issueOtp($this, PhoneNumber::parse('01722222222'), null))->toBe(OtpIssueStatus::DailyCapReached)
        ->and($this->sms->sent())->toHaveCount(3);

    Event::assertDispatchedTimes(OtpDailyCapReached::class, 1);
});

it('voids the challenge when SMS delivery fails', function () {
    $this->sms->fail = true;

    expect(issueOtp($this))->toBe(OtpIssueStatus::DeliveryFailed)
        ->and(DB::table('otp_challenges')->whereNull('consumed_at')->count())->toBe(0);
});

it('localises the SMS text', function () {
    $this->otp->issue($this->phone, OtpPurpose::Login, null, 'bn');

    expect($this->sms->sent()[0]['message'])->toContain('কোড');
});

it('purges challenges a week after expiry', function () {
    issueOtp($this);
    DB::table('otp_challenges')->update(['expires_at' => now('UTC')->subDays(8)]);

    (new PurgeExpiredOtpChallenges)->handle();

    expect(DB::table('otp_challenges')->count())->toBe(0);
});

final class BanglaTextHelper
{
    public static function toBangla(string $digits): string
    {
        return BanglaText::digitsToBangla($digits);
    }
}
