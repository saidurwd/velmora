<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Console;

use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Shipping\Actions\BookShipment;
use App\Modules\Shipping\Actions\CancelShipment;
use App\Modules\Shipping\Actions\CreateShipment;
use App\Modules\Shipping\Actions\HandOverShipment;
use App\Modules\Shipping\Actions\RecordCourierEvent;
use App\Modules\Shipping\DTOs\CourierEvent;
use App\Modules\Shipping\Exceptions\ShipmentException;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Concurrency-test entry point: each OS process (its own database connection) does ONE thing to ONE order or parcel and prints a JSON line.
 * Never available in production.
 */
class ShipmentProbeCommand extends Command
{
    protected $signature = 'shipping:probe {action : ship|book|handover|cancel|event} {--order=} {--shipment=} {--lines=} {--account=} {--actor-id=} {--courier=fake} {--consignment=} {--status=} {--at=} {--start-at=}';

    protected $description = 'Make, book, hand over or cancel one parcel and print the outcome as JSON (concurrency testing only)';

    protected $hidden = true;

    public function handle(CreateShipment $create, BookShipment $book, HandOverShipment $handOver, CancelShipment $cancel, RecordCourierEvent $record): int
    {
        if (app()->environment('production')) {
            $this->error('Not available in production.');

            return self::FAILURE;
        }

        $actor = new Actor(ActorType::Staff, $this->option('actor-id') === null ? null : (int) $this->option('actor-id'), null, 'probe');
        $lines = [];
        foreach (array_filter(explode(',', (string) $this->option('lines'))) as $pair) {
            [$item, $qty] = explode(':', $pair);
            $lines[(int) $item] = (int) $qty;
        }

        if ($at = (float) $this->option('start-at')) {
            while (microtime(true) < $at) {
                usleep(200);
            }
        }

        try {
            if ($this->argument('action') === 'event') {
                $result = $record->handle((string) $this->option('courier'), new CourierEvent((string) $this->option('consignment'), (string) $this->option('status'), CarbonImmutable::parse((string) $this->option('at'), 'UTC')), 'webhook');
                $this->line(json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }

            $shipment = match ((string) $this->argument('action')) {
                'ship' => $create->handle($actor, (string) $this->option('order'), $lines, $this->option('account') ?: null),
                'book' => $book->handle($actor, (string) $this->option('shipment')),
                'handover' => $handOver->handle($actor, (string) $this->option('shipment')),
                default => $cancel->handle($actor, (string) $this->option('shipment'), 'concurrency probe'),
            };
            $this->line(json_encode(['ok' => true, 'shipment' => $shipment->public_id, 'status' => $shipment->status->value], JSON_THROW_ON_ERROR));
        } catch (ShipmentException $e) {
            $this->line(json_encode(['ok' => false, 'reason' => $e->reason], JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            $this->line(json_encode(['ok' => false, 'reason' => 'error', 'class' => $e::class, 'message' => $e->getMessage()], JSON_THROW_ON_ERROR));
        }

        return self::SUCCESS;
    }
}
