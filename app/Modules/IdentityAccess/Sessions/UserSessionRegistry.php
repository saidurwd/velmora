<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Sessions;

use App\Modules\Platform\Contracts\Clock;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Device list + remote sign-out. The session store (Redis) cannot be searched by user, so each login is mirrored in
 * `user_sessions` (hash for lookup, encrypted id so we can destroy the real session). A session whose row was deleted
 * is rejected at its next request by EnforceSessionPolicy.
 */
final class UserSessionRegistry
{
    public function __construct(private readonly Clock $clock, private readonly SessionManager $sessions) {}

    public function register(int $userId, string $guard, string $sessionId, ?string $ip, ?string $userAgent): void
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');
        $hash = $this->hash($sessionId);

        DB::table('user_sessions')->updateOrInsert(['session_id_hash' => $hash], [
            'user_id' => $userId,
            'session_id_encrypted' => Crypt::encryptString($sessionId),
            'guard' => $guard,
            'ip' => $ip,
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
            'last_seen_at' => $now,
            'created_at' => $now,
        ]);
    }

    public function exists(string $sessionId): bool
    {
        return DB::table('user_sessions')->where('session_id_hash', $this->hash($sessionId))->exists();
    }

    /** @return object{last_seen_at: string}|null */
    public function find(string $sessionId): ?object
    {
        /** @var object{last_seen_at: string}|null $row */
        $row = DB::table('user_sessions')->where('session_id_hash', $this->hash($sessionId))->first();

        return $row;
    }

    public function touch(string $sessionId): void
    {
        DB::table('user_sessions')->where('session_id_hash', $this->hash($sessionId))
            ->update(['last_seen_at' => $this->clock->now()->format('Y-m-d H:i:s.u')]);
    }

    public function forget(string $sessionId): void
    {
        DB::table('user_sessions')->where('session_id_hash', $this->hash($sessionId))->delete();
    }

    /** @return int number of other devices signed out */
    public function revokeOthers(int $userId, string $currentSessionId, ?string $guard = null): int
    {
        $query = DB::table('user_sessions')->where('user_id', $userId)->where('session_id_hash', '!=', $this->hash($currentSessionId));
        if ($guard !== null) {
            $query->where('guard', $guard);
        }

        return $this->destroy($query->get());
    }

    public function revokeAll(int $userId): int
    {
        return $this->destroy(DB::table('user_sessions')->where('user_id', $userId)->get());
    }

    /** @return list<object> */
    public function listFor(int $userId): array
    {
        return array_values(DB::table('user_sessions')->where('user_id', $userId)->orderByDesc('last_seen_at')->get()->all());
    }

    /** @param  iterable<\stdClass>  $rows */
    private function destroy(iterable $rows): int
    {
        $count = 0;

        foreach ($rows as $row) {
            $this->sessions->driver()->getHandler()->destroy(Crypt::decryptString($row->session_id_encrypted));
            DB::table('user_sessions')->where('id', $row->id)->delete();
            $count++;
        }

        return $count;
    }

    private function hash(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }
}
