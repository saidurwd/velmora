<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Controllers\Api;

use App\Modules\IdentityAccess\Actions\ChangePassword;
use App\Modules\IdentityAccess\Auth\ApiReauth;
use App\Modules\IdentityAccess\Http\Requests\Api\ChangePasswordRequest;
use App\Modules\IdentityAccess\Http\Resources\UserResource;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\IdentityAccess\Services\DatabaseApiSessions;
use App\Modules\Platform\Enums\ErrorCode;
use App\Modules\Platform\Http\ApiProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MeController
{
    /**
     * My account
     *
     * @response array{data: array{id: string, name: string|null, email: string|null, phone: string|null, locale: string, email_verified: bool, phone_verified: bool, has_password: bool}}
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        return response()->json(['data' => UserResource::make($user)]);
    }

    /**
     * Change my password
     *
     * Needs a recent confirmation (`auth.reauth_required` otherwise: call /auth/reauth first). Every other signed-in device is signed out; this one stays.
     *
     * @status 204
     */
    public function changePassword(ChangePasswordRequest $request, ChangePassword $change, DatabaseApiSessions $sessions, ApiReauth $reauth): Response
    {
        $user = $request->user();
        assert($user instanceof User);
        $family = $sessions->currentFamily($request);

        if ($family === null || ! $reauth->isFresh($family)) {
            throw new ApiProblem(ErrorCode::ReauthRequired);
        }

        $change->handle($user, (string) $request->validated('password'), '', $family);

        return response()->noContent();
    }
}
