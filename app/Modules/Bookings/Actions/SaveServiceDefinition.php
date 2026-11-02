<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Actions;

use App\Modules\Bookings\Enums\LocationType;
use App\Modules\Bookings\Exceptions\BookingException;
use App\Modules\Bookings\Models\ServiceDefinition;
use App\Modules\Catalog\Contracts\VariantDirectory;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;

/**
 * Makes a service product bookable, or changes the rules every package of it follows: where it happens, how soon and how far ahead a customer may book, and
 * how long a chosen slot is kept while they pay. The product is named by the SKU of any of its variants and must be a service. Who may do this
 * (`bookings.manage`) is decided by the caller.
 */
final class SaveServiceDefinition
{
    public function __construct(private readonly VariantDirectory $variants, private readonly AuditLogger $audit) {}

    /** @throws BookingException */
    public function handle(Actor $actor, string $sku, string $location, int $leadTimeMin, int $maxHorizonDays, int $holdMinutes, bool $active): ServiceDefinition
    {
        $variant = $this->variants->findBySku($sku) ?? throw new BookingException('variant_unknown');
        if ($variant->productType !== 'service') {
            throw new BookingException('product_not_service');
        }
        if (LocationType::tryFrom($location) === null) {
            throw new BookingException('location_invalid');
        }
        if ($leadTimeMin < 0 || $leadTimeMin > 525600) {
            throw new BookingException('lead_time_invalid');
        }
        if ($maxHorizonDays < 1 || $maxHorizonDays > 365) {
            throw new BookingException('horizon_invalid');
        }
        if ($holdMinutes < 5 || $holdMinutes > 120) {
            throw new BookingException('hold_invalid');
        }

        $service = ServiceDefinition::query()->where('product_id', $variant->productId)->first();
        $before = $service?->only(['location_type', 'lead_time_min', 'max_horizon_days', 'hold_minutes', 'is_active']) ?? [];
        $fields = ['location_type' => $location, 'lead_time_min' => $leadTimeMin, 'max_horizon_days' => $maxHorizonDays, 'hold_minutes' => $holdMinutes, 'is_active' => $active];
        if ($service === null) {
            $service = ServiceDefinition::query()->create($fields + ['product_id' => $variant->productId, 'created_by' => $actor->id]);
        } else {
            $service->forceFill($fields)->save();
        }
        $this->audit->record($before === [] ? 'bookings.service_created' : 'bookings.service_changed', $service, $before, $fields + ['sku' => $variant->sku], $actor);

        return $service;
    }
}
