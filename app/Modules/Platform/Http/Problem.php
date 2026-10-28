<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http;

use Illuminate\Http\JsonResponse;

/** RFC 9457 problem+json with a stable machine `code` (BD §12). */
final class Problem
{
    /** @param  array<string, mixed>  $extra */
    public static function response(int $status, string $code, string $title, ?string $detail = null, array $extra = []): JsonResponse
    {
        $body = array_filter([
            'type' => 'about:blank',
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            'code' => $code,
            'request_id' => request()->headers->get('X-Request-Id'),
        ], fn ($v) => $v !== null) + $extra;

        return new JsonResponse($body, $status, ['Content-Type' => 'application/problem+json']);
    }
}
