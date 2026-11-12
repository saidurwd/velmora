<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\Api;

use App\Modules\Customers\Contracts\CustomerProfiles;
use App\Modules\Customers\Http\Requests\ProfileRequest;
use App\Modules\Platform\Enums\ErrorCode;
use App\Modules\Platform\Http\ApiProblem;
use Dedoc\Scramble\Attributes\Response as ResponseDoc;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The customer's own profile: name and birth date. Marketing choices live with the other message preferences (`/me/preferences`), in one place. */
class ProfileController
{
    /**
     * My profile
     */
    #[ResponseDoc(status: 200, type: 'array{data: array{name: string|null, birth_date: string|null}}')]
    public function show(Request $request, CustomerProfiles $profiles): JsonResponse
    {
        $profile = $profiles->findByUserId((int) $request->user()?->getAuthIdentifier()) ?? throw new ApiProblem(ErrorCode::NotFound);

        return response()->json(['data' => ['name' => $profile->name, 'birth_date' => $profile->birthDate]]);
    }

    /**
     * Change my profile
     *
     * `name` is required; `birth_date` (`YYYY-MM-DD`, in the past) is optional and cleared when empty.
     */
    #[ResponseDoc(status: 200, type: 'array{data: array{name: string|null, birth_date: string|null}}')]
    public function update(ProfileRequest $request, CustomerProfiles $profiles): JsonResponse
    {
        $user = $request->user();
        $userId = (int) $user?->getAuthIdentifier();
        // The profile row is created from an outbox event; make sure it exists even if that has not been delivered yet.
        $profiles->ensureForUser($userId, null, $user?->email, $user?->phone);
        $profiles->updateProfile($userId, (string) $request->validated('name'), $request->validated('birth_date'));
        $profile = $profiles->findByUserId($userId) ?? throw new ApiProblem(ErrorCode::NotFound);

        return response()->json(['data' => ['name' => $profile->name, 'birth_date' => $profile->birthDate]]);
    }
}
