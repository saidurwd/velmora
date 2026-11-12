<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers\Api;

use App\Modules\IdentityAccess\Contracts\ApiSessionResolver;
use App\Modules\Notifications\Actions\RegisterDevice;
use App\Modules\Notifications\Actions\RemoveDevice;
use App\Modules\Notifications\Http\Requests\RegisterDeviceRequest;
use App\Modules\Platform\Enums\ErrorCode;
use App\Modules\Platform\Http\ApiProblem;
use Dedoc\Scramble\Attributes\Response as ResponseDoc;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Push-token registration. A device belongs to the person and the app sign-in that registered it. */
class DeviceController
{
    /**
     * Register this phone for push messages
     *
     * Send it after every sign-in and whenever the push token changes. Registering the same token again updates it (200); a new one is created (201). Signing out removes the device.
     */
    #[ResponseDoc(status: 201, description: 'A new device was registered.', type: 'array{data: array{id: string, platform: string, app_version: string|null, locale: string}}')]
    #[ResponseDoc(status: 200, description: 'The device was already known and was updated.', type: 'array{data: array{id: string, platform: string, app_version: string|null, locale: string}}')]
    public function store(RegisterDeviceRequest $request, ApiSessionResolver $sessions, RegisterDevice $register): JsonResponse
    {
        $userId = (int) $request->user()?->getAuthIdentifier();
        $family = $sessions->currentFamily($request) ?? throw new ApiProblem(ErrorCode::Unauthenticated);

        $result = $register->handle($userId, $family, (string) $request->validated('push_token'), (string) $request->validated('platform'), $request->validated('app_version'), app()->getLocale());

        return response()->json(['data' => [
            'id' => $result['id'],
            'platform' => (string) $request->validated('platform'),
            'app_version' => $request->validated('app_version'),
            'locale' => app()->getLocale(),
        ]], $result['created'] ? 201 : 200);
    }

    /**
     * Stop push messages to this phone
     *
     * @status 204
     */
    public function destroy(Request $request, string $id, RemoveDevice $remove): Response
    {
        $remove->handle((int) $request->user()?->getAuthIdentifier(), $id) || throw new ApiProblem(ErrorCode::NotFound);

        return response()->noContent();
    }
}
