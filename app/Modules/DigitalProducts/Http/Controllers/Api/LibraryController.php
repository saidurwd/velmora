<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Http\Controllers\Api;

use App\Modules\DigitalProducts\Actions\DownloadDigitalFile;
use App\Modules\DigitalProducts\Actions\RevealLicenseKeys;
use App\Modules\DigitalProducts\Exceptions\DigitalException;
use App\Modules\DigitalProducts\Models\Entitlement;
use App\Modules\DigitalProducts\Services\LibraryReader;
use App\Modules\Orders\Contracts\CustomerOrders;
use App\Modules\Platform\Enums\ErrorCode;
use App\Modules\Platform\Http\ApiProblem;
use Dedoc\Scramble\Attributes\Response as ResponseDoc;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * "My library": the digital purchases of the signed-in customer's own orders. Someone else's purchase is 404 (and the attempt is logged), exactly as on the website.
 * Every download is counted once, against the limit and expiry set for the purchase, and recorded.
 */
class LibraryController
{
    /**
     * My library
     *
     * `state` is `available`, `limit_reached`, `expired`, `revoked` or `not_ready`. `left` is the downloads still allowed (`unlimited` when there is no limit). `keys_needed` / `keys_ready` count licence keys.
     */
    #[ResponseDoc(status: 200, type: 'array{data: list<array{id: string, order: string, product: string, sku: string, file: string|null, version: int|null, left: int|null, unlimited: bool, expires_at: string|null, state: string, keys_needed: int, keys_ready: int}>}')]
    public function index(Request $request, CustomerOrders $orders, LibraryReader $reader): JsonResponse
    {
        $rows = $reader->forOrders($orders->orderIdsOwnedBy($this->userId($request)), app()->getLocale());

        return response()->json(['data' => array_map(fn (array $r): array => [
            'id' => $r['id'], 'order' => $r['order'], 'product' => $r['product'], 'sku' => $r['sku'], 'file' => $r['file'], 'version' => $r['version'], 'left' => $r['left'], 'unlimited' => $r['unlimited'],
            'expires_at' => $r['expires_at']?->toIso8601String(), 'state' => $r['state'], 'keys_needed' => $r['keys_needed'], 'keys_ready' => $r['keys_ready'],
        ], $rows)]);
    }

    /**
     * Download a purchase
     *
     * Uses one download and answers a short-lived address for the file (`url`, valid until `expires_at`, about a minute): fetch it straight away; the address is not kept. A purchase with no downloads left is `entitlement.limit_reached`, an expired one `entitlement.expired`, a revoked one `entitlement.revoked`, one with no file yet `entitlement.unavailable`; none of these uses a download.
     */
    #[ResponseDoc(status: 200, type: 'array{data: array{url: string, filename: string, remaining: int|null, expires_at: string}}')]
    public function download(Request $request, string $id, DownloadDigitalFile $download): JsonResponse
    {
        $entitlement = $this->own($request, $id, $download);

        try {
            $link = $download->handle($entitlement, $this->userId($request), $request->ip(), $request->userAgent());
        } catch (DigitalException $e) {
            throw $this->problem($e);
        }

        return response()->json(['data' => ['url' => $link->url, 'filename' => $link->filename, 'remaining' => $link->remaining, 'expires_at' => $link->validUntil->toIso8601String()]])->header('Cache-Control', 'no-store');
    }

    /**
     * Show my licence keys
     *
     * The keys given to this purchase, never kept by a cache. Keys still being prepared are `entitlement.unavailable`.
     */
    #[ResponseDoc(status: 200, type: 'array{data: array{keys: list<string>}}')]
    public function keys(Request $request, string $id, DownloadDigitalFile $download, RevealLicenseKeys $reveal): JsonResponse
    {
        $entitlement = $this->own($request, $id, $download);

        try {
            $keys = $reveal->handle($entitlement, $this->userId($request));
        } catch (DigitalException $e) {
            throw $this->problem($e);
        }

        return response()->json(['data' => ['keys' => $keys]])->header('Cache-Control', 'no-store');
    }

    /** The purchase, if it is the caller's; otherwise 404, and the attempt is remembered. */
    private function own(Request $request, string $id, DownloadDigitalFile $download): Entitlement
    {
        $found = Entitlement::query()->where('public_id', $id)->first() ?? throw new ApiProblem(ErrorCode::NotFound);
        $user = $request->user();

        if ($user === null || ! Gate::forUser($user)->allows('download', $found)) {
            $download->logNotOwner($found, $this->userId($request), $request->ip(), $request->userAgent());

            throw new ApiProblem(ErrorCode::NotFound);
        }

        return $found;
    }

    private function problem(DigitalException $e): ApiProblem
    {
        $code = match ($e->reason) {
            'download_limit_reached' => ErrorCode::EntitlementLimitReached,
            'download_expired' => ErrorCode::EntitlementExpired,
            'download_revoked' => ErrorCode::EntitlementRevoked,
            default => ErrorCode::EntitlementUnavailable,
        };

        return new ApiProblem($code, [], [], (string) __('digital-products::library.errors.'.$e->reason));
    }

    private function userId(Request $request): int
    {
        return (int) $request->user()?->getAuthIdentifier();
    }
}
