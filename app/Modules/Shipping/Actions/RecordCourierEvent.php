<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Actions;

use App\Modules\Orders\Contracts\OrderFulfilment;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Outbox\Outbox;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Transactions;
use App\Modules\Shipping\DTOs\CourierEvent;
use App\Modules\Shipping\Enums\ShipmentStatus;
use App\Modules\Shipping\Events\ShipmentDelivered;
use App\Modules\Shipping\Events\ShipmentFailed;
use App\Modules\Shipping\Events\ShipmentHandedOver;
use App\Modules\Shipping\Events\ShipmentReturned;
use App\Modules\Shipping\Models\CourierStatusMapping;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Shipping\Services\ShipmentIssues;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Takes in ONE thing a courier said about a parcel (from a webhook or from asking it) and keeps the parcel right:
 *  - the event is stored exactly once, in the courier's own words and time (a repeat changes nothing);
 *  - the parcel's state follows the LATEST event by the courier's time, never the order they arrived in, and never backwards out of delivered/returned;
 *  - a word we have no meaning for is kept, raised to a person and changes nothing else (the order is untouched);
 *  - leaving the shop, delivery, a failed attempt and a return are announced through the outbox in the same transaction.
 * The parcel row is locked, the order row never is (so this cannot deadlock with making or cancelling a parcel).
 */
final class RecordCourierEvent
{
    public const RECORDED = 'recorded';

    public const DUPLICATE = 'duplicate';

    public const UNKNOWN_SHIPMENT = 'unknown_shipment';

    /** The statuses a courier can report (the same list as the status map). */
    private const MAPPED = ['handed_over', 'in_transit', 'out_for_delivery', 'delivered', 'failed', 'returned'];

    public function __construct(
        private readonly Outbox $outbox,
        private readonly OrderFulfilment $orders,
        private readonly ShipmentIssues $issues,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {}

    /** @param  'webhook'|'poll'  $source */
    public function handle(string $courier, CourierEvent $event, string $source): string
    {
        $found = Shipment::query()->where('courier', $courier)->where('courier_consignment_id', $event->consignmentId)->first();
        if ($found === null) {
            Log::warning('shipping.unknown_consignment', ['courier' => $courier, 'consignment' => $event->consignmentId]);
            $this->issues->raise('unknown_consignment', null, $courier.':'.$event->consignmentId, ['courier' => $courier, 'consignment' => $event->consignmentId, 'status' => $event->providerStatus]);

            return self::UNKNOWN_SHIPMENT;
        }

        $mapped = CourierStatusMapping::query()->where('courier', $courier)->where('provider_status', $event->providerStatus)->value('internal_status');

        return $this->apply($found, $event, $source, is_string($mapped) ? $mapped : null, null);
    }

    /**
     * Staff say what happened to a parcel (the manual courier, or a correction): the same rules as a courier's message, with the person recorded.
     * The word is one of our own statuses.
     */
    public function manual(Shipment $shipment, string $status, CarbonImmutable $occurredAt, ?string $note, int $actorId): string
    {
        return $this->apply($shipment, new CourierEvent((string) $shipment->courier_consignment_id, $status, $occurredAt, $note), 'manual', $status, $actorId);
    }

    /** @param  'webhook'|'poll'|'manual'  $source */
    private function apply(Shipment $found, CourierEvent $event, string $source, ?string $mapped, ?int $actorId): string
    {
        $result = Transactions::run(function () use ($found, $event, $source, $mapped, $actorId): array {
            $shipment = Shipment::query()->whereKey($found->id)->lockForUpdate()->firstOrFail();
            $now = $this->clock->now();

            $stored = DB::table('shipment_events')->insertOrIgnore([
                'shipment_id' => $shipment->id, 'provider_status' => mb_substr($event->providerStatus, 0, 80), 'internal_status' => $mapped ?? 'unknown', 'occurred_at' => $event->occurredAt, 'received_at' => $now,
                'source' => $source, 'actor_id' => $actorId, 'note' => $event->note === null ? null : mb_substr($event->note, 0, 255), 'summary' => $event->summary === [] ? null : json_encode($event->summary, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
            if ($stored === 0) {
                return [self::DUPLICATE, null, null];
            }

            if ($mapped === null) {
                $this->issues->raise('unknown_status', $shipment->id, $shipment->public_id.':'.$event->providerStatus, ['courier' => $shipment->courier, 'provider_status' => $event->providerStatus, 'consignment' => $event->consignmentId]);
                Log::error('shipping.unknown_status', ['courier' => $shipment->courier, 'shipment' => $shipment->public_id, 'provider_status' => $event->providerStatus]);

                return [self::RECORDED, null, null];
            }

            $from = $shipment->status;
            $to = $this->latestStatus($shipment->id);
            $changes = [];

            $attempts = (int) DB::table('shipment_events')->where('shipment_id', $shipment->id)->where('internal_status', 'failed')->count();
            if ($attempts !== $shipment->attempts) {
                $changes['attempts'] = $attempts;
            }

            $target = $to !== null && $to !== $from && $from->canMoveTo($to) ? $to : null;
            if ($target !== null) {
                $changes['status'] = $target->value;
                if ($shipment->handed_over_at === null) {
                    $changes['handed_over_at'] = $this->firstMappedTime($shipment->id) ?? $now;
                }
                if ($target === ShipmentStatus::Delivered) {
                    $changes['delivered_at'] = $this->timeOf($shipment->id, 'delivered') ?? $event->occurredAt;
                }
            }
            if ($changes !== []) {
                DB::table('shipments')->where('id', $shipment->id)->update($changes + ['updated_at' => $now]);
            }

            if ($target !== null) {
                $this->announce($shipment, $from, $target, $changes['attempts'] ?? $shipment->attempts, $event->occurredAt);
            } elseif (isset($changes['attempts']) && $to === ShipmentStatus::Failed) {
                // a further failed attempt reported while the parcel already shows "failed"
                $this->announce($shipment, ShipmentStatus::Failed, ShipmentStatus::Failed, $attempts, $event->occurredAt, again: true);
            }

            return [self::RECORDED, $from, $target];
        });

        if ($result[2] !== null) {
            $this->audit->record('shipment.status_changed', $found, ['status' => $result[1]?->value], ['status' => $result[2]->value, 'source' => $source, 'courier_status' => $event->providerStatus], $actorId === null ? Actor::system('courier') : new Actor(ActorType::Staff, $actorId, null));
        }

        return $result[0];
    }

    /** The parcel's state is the latest thing a courier reported, by the courier's time (then by arrival to break a tie). */
    private function latestStatus(int $shipmentId): ?ShipmentStatus
    {
        $status = DB::table('shipment_events')->where('shipment_id', $shipmentId)->whereIn('internal_status', self::MAPPED)->orderByDesc('occurred_at')->orderByDesc('id')->value('internal_status');

        return $status === null ? null : ShipmentStatus::from((string) $status);
    }

    private function firstMappedTime(int $shipmentId): ?CarbonImmutable
    {
        $at = DB::table('shipment_events')->where('shipment_id', $shipmentId)->whereIn('internal_status', self::MAPPED)->min('occurred_at');

        return $at === null ? null : CarbonImmutable::parse((string) $at, 'UTC');
    }

    private function timeOf(int $shipmentId, string $internal): ?CarbonImmutable
    {
        $at = DB::table('shipment_events')->where('shipment_id', $shipmentId)->where('internal_status', $internal)->min('occurred_at');

        return $at === null ? null : CarbonImmutable::parse((string) $at, 'UTC');
    }

    private function announce(Shipment $shipment, ShipmentStatus $from, ShipmentStatus $to, int $attempts, CarbonImmutable $at, bool $again = false): void
    {
        $orderPublicId = (string) DB::table('orders')->where('id', $shipment->order_id)->value('public_id');
        $args = [$shipment->id, $shipment->public_id, $shipment->order_id, $orderPublicId, $shipment->cod_amount_minor, $shipment->currency, $at->toIso8601String(), $attempts];

        switch ($to) {
            case ShipmentStatus::HandedOver:
            case ShipmentStatus::InTransit:
            case ShipmentStatus::OutForDelivery:
                if ($from === ShipmentStatus::Created) {
                    $this->outbox->record(new ShipmentHandedOver(...$args));
                }
                break;
            case ShipmentStatus::Delivered:
                $this->outbox->record(new ShipmentDelivered(...$args, detail: $this->orderDelivered($shipment) ? 'all_delivered' : null));
                break;
            case ShipmentStatus::Failed:
                $final = $attempts >= (int) config('shipping.max_delivery_attempts');
                $this->outbox->record(new ShipmentFailed(...$args, detail: $final ? 'final' : null));
                if ($final) {
                    $this->issues->raise('attempts_exhausted', $shipment->id, $shipment->public_id, ['attempts' => $attempts]);
                }
                break;
            case ShipmentStatus::Returned:
                $this->outbox->record(new ShipmentReturned(...$args));
                $this->issues->raise('returned_to_origin', $shipment->id, $shipment->public_id, ['attempts' => $attempts]);
                break;
            default:
                break;
        }
    }

    /** This parcel was the last one: every physical line is on a parcel and every parcel that is not cancelled has been delivered. */
    private function orderDelivered(Shipment $delivering): bool
    {
        $open = DB::table('shipments')->where('order_id', $delivering->order_id)->whereNotIn('status', ['cancelled', 'delivered'])->where('id', '!=', $delivering->id)->exists();

        return ! $open && $this->orders->isFullyShipped($delivering->order_id);
    }
}
