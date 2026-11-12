<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Actions;

use App\Modules\IdentityAccess\DTOs\ApiTokenPair;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Transactions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Starts an app sign-in (ADR P11-2): a short access token (a Sanctum token that can only act as a customer) and a long refresh token that is
 * swapped for a new pair every time it is used. Only the SHA-256 of the refresh token is kept. The caller has ALREADY proved who the person is
 * (password or phone code); this Action decides nothing about that. `$family` is given only when a refresh continues an existing sign-in.
 */
final class IssueApiSession
{
    public function __construct(private readonly Clock $clock, private readonly AuditLogger $audit, private readonly EndApiSessions $end) {}

    public function start(User $user, string $deviceName, ?string $platform, string $method): ApiTokenPair
    {
        $family = strtolower((string) Str::ulid());
        $now = $this->clock->now();

        $pair = Transactions::run(function () use ($user, $deviceName, $platform, $family, $now): ApiTokenPair {
            $this->makeRoomFor($user->id);

            return $this->issue($user, $family, $this->cleanName($deviceName), $platform, $now, $now);
        });

        $this->audit->record('auth.api_login', $user, after: ['method' => $method, 'platform' => $platform, 'session' => $family], actor: new Actor(ActorType::Customer, $user->id, $user->public_id));

        return $pair;
    }

    /** Continues a sign-in with a fresh pair (used by the refresh). Runs inside the caller's transaction. */
    public function continue(User $user, string $family, string $deviceName, ?string $platform, CarbonImmutable $signedInAt): ApiTokenPair
    {
        return $this->issue($user, $family, $deviceName, $platform, $signedInAt, $this->clock->now());
    }

    private function issue(User $user, string $family, string $deviceName, ?string $platform, CarbonImmutable $signedInAt, CarbonImmutable $now): ApiTokenPair
    {
        $accessSeconds = (int) config('api.tokens.access_minutes') * 60;
        $refreshSeconds = (int) config('api.tokens.refresh_days') * 86400;

        $access = $user->createToken($deviceName, ['customer'], $now->addSeconds($accessSeconds));
        $refresh = 'vrt_'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        DB::table('refresh_tokens')->insert([
            'user_id' => $user->id,
            'family_id' => $family,
            'token_hash' => hash('sha256', $refresh),
            'access_token_id' => $access->accessToken->getKey(),
            'status' => 'active',
            'device_name' => $deviceName,
            'platform' => $platform,
            'signed_in_at' => $signedInAt->format('Y-m-d H:i:s.u'),
            'created_at' => $now->format('Y-m-d H:i:s.u'),
            'expires_at' => $now->addSeconds($refreshSeconds)->format('Y-m-d H:i:s.u'),
        ]);

        return new ApiTokenPair($access->plainTextToken, $accessSeconds, $refresh, $refreshSeconds, $family, $user);
    }

    /** A person keeps at most N live sign-ins; the oldest ones end when a new one starts. */
    private function makeRoomFor(int $userId): void
    {
        $max = max(1, (int) config('api.tokens.max_sessions_per_user'));
        $families = DB::table('refresh_tokens')->where('user_id', $userId)->where('status', 'active')->orderBy('signed_in_at')->orderBy('id')->pluck('family_id')->all();

        foreach (array_slice($families, 0, max(0, count($families) - $max + 1)) as $old) {
            $this->end->family($userId, (string) $old, 'limit');
        }
    }

    private function cleanName(string $name): string
    {
        $clean = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name));

        return mb_substr($clean === '' ? 'App' : $clean, 0, 80);
    }
}
