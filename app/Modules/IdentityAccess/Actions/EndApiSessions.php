<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Actions;

use App\Modules\IdentityAccess\Events\ApiSessionsEnded;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Support\AuditLogger;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Ends app sign-ins: this device (logout), one chosen from the list, all of them, all but the current one (password change), or one found stolen.
 * Ending = every refresh token of the family is revoked AND its access token is deleted, so nothing about it works on the next request.
 */
final class EndApiSessions
{
    public function __construct(private readonly Clock $clock, private readonly AuditLogger $audit) {}

    /** @return bool whether a live sign-in of this person was ended */
    public function family(int $userId, string $family, string $why = 'logout'): bool
    {
        $live = DB::table('refresh_tokens')->where('user_id', $userId)->where('family_id', $family)->where('status', '!=', 'revoked')->exists();
        $this->revoke(DB::table('refresh_tokens')->where('user_id', $userId)->where('family_id', $family));
        $this->announce($userId, [$family]);

        if ($live && $why !== 'logout') {
            $this->record($userId, $why, [$family]);
        }

        return $live;
    }

    /** Every sign-in of the person, optionally keeping one (the device that just changed the password). */
    public function all(int $userId, ?string $except = null, string $why = 'logout_all'): void
    {
        $query = DB::table('refresh_tokens')->where('user_id', $userId);
        if ($except !== null) {
            $query->where('family_id', '!=', $except);
        }
        $families = array_values(array_map('strval', (clone $query)->where('status', '!=', 'revoked')->distinct()->pluck('family_id')->all()));

        $this->revoke($query);
        // access tokens of this person that no refresh row points at (never normal, but a leftover must not survive "all")
        $leftovers = DB::table('personal_access_tokens')->where('tokenable_type', (new User)->getMorphClass())->where('tokenable_id', $userId);
        if ($except !== null) {
            $keep = DB::table('refresh_tokens')->where('family_id', $except)->whereNotNull('access_token_id')->pluck('access_token_id')->all();
            $leftovers->whereNotIn('id', $keep);
        }
        $leftovers->delete();

        if ($families !== []) {
            $this->announce($userId, $except === null ? null : $families);
            $this->record($userId, $why, $families);
        }
    }

    /** @param  Builder  $rows */
    private function revoke($rows): void
    {
        $ids = (clone $rows)->whereNotNull('access_token_id')->pluck('access_token_id')->all();
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');

        DB::transaction(function () use ($rows, $ids, $now): void {
            (clone $rows)->where('status', '!=', 'revoked')->update(['status' => 'revoked', 'revoked_at' => $now]);
            if ($ids !== []) {
                DB::table('personal_access_tokens')->whereIn('id', $ids)->delete();
            }
        });
    }

    /** @param  list<string>|null  $families */
    private function announce(int $userId, ?array $families): void
    {
        DB::afterCommit(fn () => Event::dispatch(new ApiSessionsEnded($userId, $families)));
    }

    /** @param  list<string>  $families */
    private function record(int $userId, string $why, array $families): void
    {
        $action = match ($why) {
            'reuse' => 'auth.api_refresh_reuse',
            'logout_all' => 'auth.api_logout_all',
            default => 'auth.api_sessions_ended',
        };
        $this->audit->record($action, subject: 'users', after: ['user' => $userId, 'sessions' => $families, 'why' => $why], actor: new Actor(ActorType::System, label: 'auth'), subjectId: $userId);
    }
}
