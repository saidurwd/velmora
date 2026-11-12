<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Controllers\Api;

use App\Modules\Orders\Contracts\ApiOrderAccess;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Enums\ErrorCode;
use App\Modules\Platform\Http\ApiProblem;
use App\Modules\Platform\Http\Resources\ApiTypes;
use App\Modules\Platform\Http\Resources\MoneyData;
use App\Modules\Shipping\Services\OrderTracking;
use Dedoc\Scramble\Attributes\Response as ResponseDoc;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrackingController
{
    /**
     * Where is my order
     *
     * Each parcel's stage in plain words, what is in it, the courier's tracking link, a timeline in the shop's own statuses, and what has not been sent yet. No address, phone numbers or courier notes. The signed-in owner, or a guest with the order's `X-Order-Token`.
     *
     * @unauthenticated
     */
    #[ResponseDoc(status: 200, type: 'array{data: array{number: string, cancelled: bool, delivered: bool, parcels: list<array{label: int, status: string, courier: string|null, tracking_code: string|null, tracking_url: string|null, cash_to_collect: '.ApiTypes::MONEY.', attempts: int, items: list<array{name: string, quantity: int}>, events: list<array{status: string, at: string}>}>, unsent: list<array{name: string, quantity: int}>}}')]
    public function show(Request $request, string $id, ApiOrderAccess $access, OrderTracking $tracking): JsonResponse
    {
        $grant = $access->find($request, $id) ?? throw new ApiProblem(ErrorCode::NotFound);
        $data = $tracking->for($grant->publicId, app()->getLocale()) ?? throw new ApiProblem(ErrorCode::NotFound);

        return response()->json(['data' => [
            'number' => $data['number'], 'cancelled' => $data['cancelled'], 'delivered' => $data['delivered'],
            'parcels' => array_map(fn (array $p): array => [
                'label' => $p['label'], 'status' => $p['status'], 'courier' => $p['courier'], 'tracking_code' => $p['tracking_code'], 'tracking_url' => $p['tracking_url'],
                'cash_to_collect' => MoneyData::from(Money::of($p['cod_minor'], $p['currency'])), 'attempts' => $p['attempts'], 'items' => $p['items'],
                'events' => array_map(fn (array $e): array => ['status' => $e['status'], 'at' => $e['at']->toIso8601String()], $p['events']),
            ], $data['parcels']),
            'unsent' => $data['unsent'],
        ]]);
    }
}
