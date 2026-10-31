<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Controllers\Api;

use App\Modules\Shipping\Actions\RecordCourierEvent;
use App\Modules\Shipping\Exceptions\CourierException;
use App\Modules\Shipping\Services\CourierRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A courier telling us what happened to parcels. It cannot sign in, so the adapter checks that the request is genuine exactly as that courier's documents
 * describe, and a request that fails the check is refused and recorded nowhere. Repeats are harmless (each event is stored once).
 */
class CourierWebhookController
{
    public function __invoke(Request $request, CourierRegistry $couriers, RecordCourierEvent $record, string $courier): JsonResponse
    {
        if (! $couriers->has($courier) || ! $couriers->for($courier)->capabilities()->webhook) {
            return response()->json(['message' => 'Not found'], 404);
        }

        try {
            $events = $couriers->for($courier)->parseWebhook($request);
        } catch (CourierException $e) {
            return $e->reason === 'webhook_unauthenticated' ? response()->json(['message' => 'Unauthorized'], 401) : response()->json(['message' => 'Unprocessable'], 422);
        }

        $stored = 0;
        foreach ($events as $event) {
            $stored += $record->handle($courier, $event, 'webhook') === RecordCourierEvent::RECORDED ? 1 : 0;
        }

        return response()->json(['received' => count($events), 'stored' => $stored]);
    }
}
