<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Controllers\Api;

use App\Modules\IdentityAccess\Actions\EndApiSessions;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\IdentityAccess\Services\DatabaseApiSessions;
use App\Modules\Platform\Enums\ErrorCode;
use App\Modules\Platform\Http\ApiProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** "Where am I signed in": the person's own app sign-ins, and ending one of them. */
class SessionController
{
    /**
     * My signed-in devices
     *
     * @response array{data: list<array{id: string, device_name: string, platform: string|null, signed_in_at: string, last_used_at: string|null, current: bool}>}
     */
    public function index(Request $request, DatabaseApiSessions $sessions): JsonResponse
    {
        return response()->json(['data' => $sessions->liveFor($this->user($request), $request)]);
    }

    /**
     * Sign out one device
     *
     * `id` is the `id` from the list (also the `session_id` given at sign-in). Somebody else's, an ended one or an unknown one is 404.
     *
     * @status 204
     */
    public function destroy(Request $request, string $id, DatabaseApiSessions $sessions, EndApiSessions $end): Response
    {
        $user = $this->user($request);
        $sessions->ownsLive($user, $id) || throw new ApiProblem(ErrorCode::NotFound);
        $end->family($user->id, $id);

        return response()->noContent();
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
