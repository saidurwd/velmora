<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Actions;

use App\Modules\IdentityAccess\DTOs\RefreshResult;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Support\Transactions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Swaps a refresh token for a new pair (ADR P11-2). The token is CLAIMED by one conditional UPDATE (active -> used, not expired), so of any number
 * of simultaneous calls with the same token exactly one claims it. A token that is found already used is a stolen copy: the whole sign-in is ended,
 * including the newest pair, and the caller is told so. Anything else (unknown, expired, ended, account no longer allowed) is simply invalid.
 */
final class RotateRefreshToken
{
    public function __construct(private readonly Clock $clock, private readonly IssueApiSession $issue, private readonly EndApiSessions $end) {}

    public function handle(string $refreshToken): RefreshResult
    {
        $hash = hash('sha256', $refreshToken);
        $now = $this->clock->now();
        $stamp = $now->format('Y-m-d H:i:s.u');

        $result = Transactions::run(function () use ($hash, $stamp): ?RefreshResult {
            $claimed = DB::table('refresh_tokens')->where('token_hash', $hash)->where('status', 'active')->where('expires_at', '>', $stamp)
                ->update(['status' => 'used', 'used_at' => $stamp, 'last_used_at' => $stamp]);

            if ($claimed !== 1) {
                return null;
            }

            $row = DB::table('refresh_tokens')->where('token_hash', $hash)->first();
            $user = $row === null ? null : User::query()->whereKey($row->user_id)->first();

            if ($row === null || $user === null || ! $this->mayStillSignIn($user)) {
                return RefreshResult::invalid(); // committed as `used`; the caller below ends the family
            }

            if ($row->access_token_id !== null) {
                DB::table('personal_access_tokens')->where('id', $row->access_token_id)->delete();
            }

            return RefreshResult::renewed($this->issue->continue($user, (string) $row->family_id, (string) $row->device_name, $row->platform, CarbonImmutable::parse($row->signed_in_at, 'UTC')));
        });

        if ($result !== null) {
            if ($result->pair === null) {
                $row = DB::table('refresh_tokens')->where('token_hash', $hash)->first();
                $row !== null && $this->end->family((int) $row->user_id, (string) $row->family_id, 'account');
            }

            return $result;
        }

        $row = DB::table('refresh_tokens')->where('token_hash', $hash)->first();
        if ($row !== null && $row->status === 'used') {
            $this->end->family((int) $row->user_id, (string) $row->family_id, 'reuse');

            return RefreshResult::reused();
        }

        return RefreshResult::invalid();
    }

    private function mayStillSignIn(User $user): bool
    {
        return ! $user->isStaff() && $user->isActive();
    }
}
