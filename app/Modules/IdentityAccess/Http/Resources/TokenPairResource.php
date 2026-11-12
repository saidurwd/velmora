<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Resources;

use App\Modules\IdentityAccess\DTOs\ApiTokenPair;

final class TokenPairResource
{
    /** @return array{token_type: string, access_token: string, expires_in: int, refresh_token: string, refresh_expires_in: int, session_id: string, user: array<string, mixed>} */
    public static function make(ApiTokenPair $pair): array
    {
        return [
            'token_type' => 'Bearer',
            'access_token' => $pair->accessToken,
            'expires_in' => $pair->accessExpiresIn,
            'refresh_token' => $pair->refreshToken,
            'refresh_expires_in' => $pair->refreshExpiresIn,
            'session_id' => $pair->sessionId,
            'user' => UserResource::make($pair->user),
        ];
    }
}
