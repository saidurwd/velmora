<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Controllers\Api;

use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Enums\ErrorCode;
use App\Modules\Platform\Http\ApiProblem;
use App\Modules\Shipping\Actions\RequestReturn;
use App\Modules\Shipping\Actions\WithdrawOwnReturn;
use App\Modules\Shipping\Exceptions\ShipmentException;
use App\Modules\Shipping\Http\Requests\Api\RequestReturnApiRequest;
use App\Modules\Shipping\Services\ReturnPortal;
use Dedoc\Scramble\Attributes\Response as ResponseDoc;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Sending things back. The signed-in owner only; somebody else's order or return is "not found". */
class ReturnController
{
    /**
     * Which lines can go back
     *
     * `position` is the line's place in the order (first line is 1): send it back to name the line. `left` is how many may still be returned, `until` the last day, `open` whether it can be asked for now. `reasons` are the codes to choose from.
     */
    #[ResponseDoc(status: 200, type: 'array{data: array{reasons: list<string>, lines: list<array{position: int, name: string, left: int, until: string|null, open: bool}>}}')]
    public function eligibility(Request $request, string $id, ReturnPortal $portal): JsonResponse
    {
        $lines = $portal->lines($this->userId($request), $id, app()->getLocale()) ?? throw new ApiProblem(ErrorCode::NotFound);

        return response()->json(['data' => [
            'reasons' => RequestReturn::REASONS,
            'lines' => array_map(fn (array $l): array => ['position' => $l['position'], 'name' => $l['name'], 'left' => $l['left'], 'until' => $l['until']?->toIso8601String(), 'open' => $l['open']], $lines),
        ]]);
    }

    /**
     * Ask to send items back
     *
     * Only delivered items, inside the return window, and not more than were delivered. A refused request is `return.not_eligible` with the reason in `detail`.
     */
    #[ResponseDoc(status: 201, type: 'array{data: array{id: string, status: string}}')]
    public function store(RequestReturnApiRequest $request, string $id, ReturnPortal $portal, RequestReturn $return): JsonResponse
    {
        $actor = $this->actor($request);
        $items = [];
        foreach ((array) $request->validated('lines') as $line) {
            $item = $portal->itemAt($this->userId($request), $id, (int) $line['position']) ?? throw new ApiProblem(ErrorCode::NotFound);
            $items[$item] = (int) $line['quantity'];
        }

        try {
            $made = $return->handle($actor, $id, $items, (string) $request->validated('reason_code'), $request->validated('note'));
        } catch (ShipmentException $e) {
            throw $this->problem($e);
        }

        return response()->json(['data' => ['id' => $made->public_id, 'status' => $made->status->value]], 201);
    }

    /**
     * My returns
     *
     * Newest first, with their items and the shop's note once decided.
     */
    #[ResponseDoc(status: 200, type: 'array{data: list<array{id: string, order: string, status: string, reason_code: string, reason: string|null, note: string|null, payer: string, created_at: string, items: list<array{name: string, quantity: int}>}>}')]
    public function index(Request $request, ReturnPortal $portal): JsonResponse
    {
        return response()->json(['data' => $portal->mine($this->userId($request), app()->getLocale())]);
    }

    /**
     * Take a return request back
     *
     * Only while the shop has not acted on it.
     */
    #[ResponseDoc(status: 204, type: 'null')]
    public function withdraw(Request $request, string $id, WithdrawOwnReturn $withdraw): Response
    {
        try {
            $withdraw->handle($this->actor($request), $id);
        } catch (ShipmentException $e) {
            throw $this->problem($e);
        }

        return response()->noContent();
    }

    private function problem(ShipmentException $e): ApiProblem
    {
        if (in_array($e->reason, ['order_not_found', 'return_not_found'], true)) {
            return new ApiProblem(ErrorCode::NotFound); // never reveal that someone else's exists
        }

        return new ApiProblem(ErrorCode::ReturnNotEligible, ['params' => ['reason' => $e->reason] + $e->params], detail: __('shipping::shipments.errors.'.$e->reason, $e->params));
    }

    private function userId(Request $request): int
    {
        return (int) $request->user()?->getAuthIdentifier();
    }

    private function actor(Request $request): Actor
    {
        $user = $request->user();

        return new Actor(ActorType::Customer, (int) $user?->getAuthIdentifier(), (string) ($user->public_id ?? ''), (string) ($user->name ?? ''));
    }
}
