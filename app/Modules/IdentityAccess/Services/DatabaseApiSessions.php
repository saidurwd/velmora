<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Services;

use App\Modules\IdentityAccess\Contracts\ApiSessionResolver;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Platform\Contracts\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Reads the app sign-ins of a person: which one this request is, and the list for "my devices". */
final class DatabaseApiSessions implements ApiSessionResolver
{
    public function __construct(private readonly Clock $clock) {}

    public function currentFamily(Request $request): ?string
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        $tokenId = $user->currentAccessToken()->getKey(); // routes use auth:sanctum with bearer tokens only, so this is always a stored token

        $family = DB::table('refresh_tokens')->where('user_id', $user->id)->where('access_token_id', $tokenId)->where('status', 'active')->value('family_id');

        return is_string($family) ? $family : null;
    }

    /** @return list<array{id: string, device_name: string, platform: string|null, signed_in_at: string, last_used_at: string|null, current: bool}> */
    public function liveFor(User $user, Request $request): array
    {
        $current = $this->currentFamily($request);

        $rows = DB::table('refresh_tokens as r')
            ->leftJoin('personal_access_tokens as t', 't.id', '=', 'r.access_token_id')
            ->where('r.user_id', $user->id)->where('r.status', 'active')->where('r.expires_at', '>', $this->clock->now()->format('Y-m-d H:i:s.u'))
            ->orderByDesc('r.signed_in_at')->orderByDesc('r.id')
            ->get(['r.family_id', 'r.device_name', 'r.platform', 'r.signed_in_at', 'r.created_at', 't.last_used_at']);

        return array_values($rows->map(fn ($r): array => [
            'id' => (string) $r->family_id,
            'device_name' => (string) $r->device_name,
            'platform' => is_string($r->platform) ? $r->platform : null,
            'signed_in_at' => $this->iso((string) $r->signed_in_at),
            'last_used_at' => $this->iso((string) ($r->last_used_at ?? $r->created_at)),
            'current' => $r->family_id === $current,
        ])->all());
    }

    public function ownsLive(User $user, string $family): bool
    {
        return DB::table('refresh_tokens')->where('user_id', $user->id)->where('family_id', $family)->where('status', 'active')->exists();
    }

    private function iso(string $utc): string
    {
        return CarbonImmutable::parse($utc, 'UTC')->toIso8601String();
    }
}
