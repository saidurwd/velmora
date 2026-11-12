<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Http\Controllers\Api;

use App\Modules\Platform\Enums\ErrorCode;
use App\Modules\Platform\Http\ApiProblem;
use App\Modules\Privacy\Actions\CancelErasureRequest;
use App\Modules\Privacy\Actions\DownloadExport;
use App\Modules\Privacy\Actions\RequestErasure;
use App\Modules\Privacy\Actions\RequestExport;
use App\Modules\Privacy\Exceptions\PrivacyException;
use App\Modules\Privacy\Http\Requests\RequestErasureApiRequest;
use App\Modules\Privacy\Services\PrivacyReader;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\IgnoreResponse;
use Dedoc\Scramble\Attributes\Response as ResponseDoc;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The customer's privacy requests: a copy of their data, and erasure of their account. Always the signed-in customer's own; another customer's request is "not
 * found". Asking, downloading and cancelling need a fresh confirmation of this sign-in (`POST /auth/reauth`). Erasure still needs the shop's approval.
 */
class PrivacyController
{
    private const REQUEST = 'array{id: string, type: string, status: string, requested_at: string, expires_at: string|null, decision_reason: string|null}';

    /**
     * My privacy requests
     *
     * Newest first. `blockers` lists what stands in the way of erasing the account right now (for example an order still on its way).
     */
    #[ResponseDoc(status: 200, type: 'array{data: array{requests: list<'.self::REQUEST.'>, erasure_blockers: list<string>}}')]
    public function index(Request $request, PrivacyReader $reader): JsonResponse
    {
        $userId = $this->userId($request);
        $requests = $reader->forCustomer($userId);
        $openErasure = collect($requests)->contains(fn ($r) => $r->type === 'erasure' && in_array($r->status, ['requested', 'approved', 'blocked'], true));

        return response()->json(['data' => [
            'requests' => array_map(fn ($r): array => ['id' => (string) $r->public_id, 'type' => (string) $r->type, 'status' => (string) $r->status, 'requested_at' => $this->iso($r->requested_at), 'expires_at' => $r->expires_at === null ? null : $this->iso($r->expires_at), 'decision_reason' => $r->decision_reason], $requests),
            'erasure_blockers' => $openErasure ? $reader->blockersFor($userId) : [],
        ]]);
    }

    /**
     * Ask for a copy of my data
     *
     * Built in the background; one a day. When `status` is `ready`, fetch it with the download call.
     */
    #[ResponseDoc(status: 202, type: 'array{data: array{id: string, status: string}}')]
    #[IgnoreResponse(200)]
    public function requestExport(Request $request, RequestExport $export): JsonResponse
    {
        try {
            $made = $export->handle($this->userId($request), app()->getLocale());
        } catch (PrivacyException $e) {
            throw $this->problem($e);
        }

        return response()->json(['data' => ['id' => $made->public_id, 'status' => (string) $made->status]], 202);
    }

    /**
     * Download my data
     *
     * The copy itself, as a JSON file, sent only to this signed-in, freshly confirmed caller (never as a link anyone could open). Kept 7 days; every download is recorded.
     */
    #[ResponseDoc(status: 200, type: 'array<string, mixed>')]
    public function download(Request $request, DownloadExport $download, string $id): Response
    {
        try {
            $json = $download->handle($this->userId($request), $id);
        } catch (PrivacyException $e) {
            throw $this->problem($e);
        }

        return response($json, 200, ['Content-Type' => 'application/json; charset=utf-8', 'Content-Disposition' => 'attachment; filename="my-data.json"', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    /**
     * Ask for my account to be erased
     *
     * Send `understand: true`. The shop reviews it; it is refused while an order is still being handled or a booking is still to come. You can cancel while it waits.
     */
    #[ResponseDoc(status: 202, type: 'array{data: array{id: string, status: string}}')]
    #[IgnoreResponse(200)]
    public function requestErasure(RequestErasureApiRequest $request, RequestErasure $erase): JsonResponse
    {
        try {
            $made = $erase->handle($this->userId($request), $request->validated('reason'), app()->getLocale());
        } catch (PrivacyException $e) {
            throw $this->problem($e);
        }

        return response()->json(['data' => ['id' => $made->public_id, 'status' => (string) $made->status]], 202);
    }

    /**
     * Cancel an erasure request
     *
     * Only while it is still waiting.
     *
     * @status 204
     */
    public function cancelErasure(Request $request, CancelErasureRequest $cancel, string $id): Response
    {
        try {
            $cancel->handle($this->userId($request), $id);
        } catch (PrivacyException $e) {
            throw $this->problem($e);
        }

        return response()->noContent();
    }

    private function problem(PrivacyException $e): ApiProblem
    {
        return $e->reason === 'not_found'
            ? new ApiProblem(ErrorCode::NotFound)
            : new ApiProblem(ErrorCode::PrivacyRequestRefused, ['params' => ['reason' => $e->reason]], detail: (string) __('privacy::errors.'.$e->reason));
    }

    private function iso(mixed $at): string
    {
        return CarbonImmutable::parse((string) $at, 'UTC')->toIso8601String();
    }

    private function userId(Request $request): int
    {
        return (int) $request->user()?->getAuthIdentifier();
    }
}
