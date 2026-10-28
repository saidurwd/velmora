<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Otp;

use App\Modules\IdentityAccess\Enums\OtpIssueStatus;
use App\Modules\IdentityAccess\Enums\OtpPurpose;
use App\Modules\IdentityAccess\Enums\OtpVerifyResult;
use App\Modules\IdentityAccess\Events\OtpDailyCapReached;
use App\Modules\Notifications\Contracts\SmsGateway;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\PhoneNumber;
use App\Modules\Platform\Support\BanglaText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/**
 * One-time codes over SMS. Codes are never stored: only a keyed HMAC bound to (target, purpose, challenge id).
 * A 6-digit code is brute-forceable offline from a leaked hash, so the real protection is the short TTL, the attempt cap
 * (conditional UPDATE, race-safe) and the send limits — not the hash.
 */
final class OtpService
{
    public function __construct(private readonly SmsGateway $sms, private readonly Clock $clock) {}

    public function issue(PhoneNumber $to, OtpPurpose $purpose, ?string $ip = null, ?string $locale = null): OtpIssueStatus
    {
        $target = $to->e164();
        $now = $this->clock->now();

        if ($blocked = $this->checkLimits($target, $purpose, $ip)) {
            return $blocked;
        }

        $code = str_pad((string) random_int(0, 10 ** (int) config('otp.length') - 1), (int) config('otp.length'), '0', STR_PAD_LEFT);

        $id = DB::transaction(function () use ($target, $purpose, $ip, $now, $code): int {
            // a new code kills any older open code for the same target+purpose
            DB::table('otp_challenges')->where('target', $target)->where('purpose', $purpose->value)->whereNull('consumed_at')
                ->update(['consumed_at' => $now->format('Y-m-d H:i:s.u')]);

            $id = (int) DB::table('otp_challenges')->insertGetId([
                'target' => $target,
                'purpose' => $purpose->value,
                'code_hash' => 'pending',
                'max_attempts' => (int) config('otp.max_attempts'),
                'ip' => $ip,
                'expires_at' => $now->addSeconds((int) config('otp.ttl_seconds'))->format('Y-m-d H:i:s.u'),
                'created_at' => $now->format('Y-m-d H:i:s.u'),
            ]);

            DB::table('otp_challenges')->where('id', $id)->update(['code_hash' => $this->hash($code, $target, $purpose, $id)]);

            return $id;
        });

        $result = $this->sms->send($to, $this->message($code, $locale), ['purpose' => $purpose->value]);

        if (! $result->accepted) {
            DB::table('otp_challenges')->where('id', $id)->update(['consumed_at' => $now->format('Y-m-d H:i:s.u')]);
            Log::warning('otp.delivery_failed', ['purpose' => $purpose->value, 'target' => $to->masked()]);

            return OtpIssueStatus::DeliveryFailed;
        }

        return OtpIssueStatus::Sent;
    }

    public function verify(PhoneNumber $target, OtpPurpose $purpose, string $code): OtpVerifyResult
    {
        $e164 = $target->e164();
        $code = BanglaText::numeric($code);
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');

        $challenge = DB::table('otp_challenges')->where('target', $e164)->where('purpose', $purpose->value)->whereNull('consumed_at')
            ->orderByDesc('id')->first();

        if ($challenge === null) {
            $this->hash($code, $e164, $purpose, 0); // keep timing similar to the real path

            return OtpVerifyResult::NoChallenge;
        }

        if ($challenge->expires_at <= $now) {
            return OtpVerifyResult::Expired;
        }

        // Count the attempt first and atomically; 0 rows = locked (cap reached) or already consumed by a racing request.
        $counted = DB::table('otp_challenges')->where('id', $challenge->id)->whereNull('consumed_at')
            ->whereColumn('attempts', '<', 'max_attempts')->where('expires_at', '>', $now)
            ->increment('attempts');

        if ($counted === 0) {
            return OtpVerifyResult::Locked;
        }

        if (! hash_equals((string) $challenge->code_hash, $this->hash($code, $e164, $purpose, (int) $challenge->id))) {
            return OtpVerifyResult::Invalid;
        }

        // Single use even under concurrent submissions: only one UPDATE can flip consumed_at.
        $consumed = DB::table('otp_challenges')->where('id', $challenge->id)->whereNull('consumed_at')->update(['consumed_at' => $now]);

        return $consumed === 1 ? OtpVerifyResult::Verified : OtpVerifyResult::Locked;
    }

    private function checkLimits(string $target, OtpPurpose $purpose, ?string $ip): ?OtpIssueStatus
    {
        $now = $this->clock->now();
        $fmt = fn ($t) => $t->format('Y-m-d H:i:s.u');

        $last = DB::table('otp_challenges')->where('target', $target)->orderByDesc('id')->value('created_at');
        if ($last !== null && $now->diffInSeconds(CarbonImmutable::parse((string) $last, 'UTC'), true) < (int) config('otp.resend_cooldown_seconds')) {
            return OtpIssueStatus::CoolingDown;
        }

        $targetSends = DB::table('otp_challenges')->where('target', $target)
            ->where('created_at', '>', $fmt($now->subSeconds((int) config('otp.target_window_seconds'))))->count();
        if ($targetSends >= (int) config('otp.max_sends_per_target')) {
            return OtpIssueStatus::TargetLimited;
        }

        if ($ip !== null && DB::table('otp_challenges')->where('ip', $ip)->where('created_at', '>', $fmt($now->subHour()))->count() >= (int) config('otp.max_sends_per_ip_hour')) {
            return OtpIssueStatus::IpLimited;
        }

        $cap = (int) config('otp.daily_cap');
        $day = $now->format('Y-m-d');
        if (DB::table('otp_challenges')->where('created_at', '>=', $day.' 00:00:00')->count() >= $cap) {
            // alert once per day, not once per attempt
            if (Cache::add("otp:cap-alert:{$day}", true, 86400)) {
                Log::critical('otp.daily_cap_reached', ['cap' => $cap, 'day' => $day]);
                Event::dispatch(new OtpDailyCapReached($cap, $day));
            }

            return OtpIssueStatus::DailyCapReached;
        }

        return null;
    }

    private function hash(string $code, string $target, OtpPurpose $purpose, int $challengeId): string
    {
        return hash_hmac('sha256', "{$code}|{$target}|{$purpose->value}|{$challengeId}", (string) config('app.key'));
    }

    private function message(string $code, ?string $locale): string
    {
        return __('identity-access::otp.message', ['code' => $code, 'app' => config('app.name'), 'minutes' => (int) config('otp.ttl_seconds') / 60], $locale);
    }
}
