<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Actions;

use App\Modules\Bookings\Exceptions\BookingException;
use App\Modules\Bookings\Models\ServiceDefinition;
use App\Modules\Bookings\Models\ServicePackage;
use App\Modules\Catalog\Contracts\VariantDirectory;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;

/**
 * Makes one variant of a bookable service a package a customer can pick: how long it takes and how much time is kept free before and after. The variant
 * must belong to a service product that has been made bookable. Who may do this (`bookings.manage`) is decided by the caller.
 */
final class SaveServicePackage
{
    public function __construct(private readonly VariantDirectory $variants, private readonly AuditLogger $audit) {}

    /** @throws BookingException */
    public function handle(Actor $actor, string $variantSku, int $durationMin, int $bufferBeforeMin, int $bufferAfterMin, bool $active): ServicePackage
    {
        $variant = $this->variants->findBySku($variantSku) ?? throw new BookingException('variant_unknown');
        if ($variant->productType !== 'service') {
            throw new BookingException('product_not_service');
        }
        $service = ServiceDefinition::query()->where('product_id', $variant->productId)->first() ?? throw new BookingException('service_not_defined');
        if ($durationMin < 5 || $durationMin > 1440) {
            throw new BookingException('duration_invalid');
        }
        if ($bufferBeforeMin < 0 || $bufferBeforeMin > 240 || $bufferAfterMin < 0 || $bufferAfterMin > 240) {
            throw new BookingException('buffer_invalid');
        }

        $package = ServicePackage::query()->where('variant_id', $variant->id)->first();
        $before = $package?->only(['duration_min', 'buffer_before_min', 'buffer_after_min', 'is_active']) ?? [];
        $fields = ['duration_min' => $durationMin, 'buffer_before_min' => $bufferBeforeMin, 'buffer_after_min' => $bufferAfterMin, 'is_active' => $active];
        if ($package === null) {
            $package = ServicePackage::query()->create($fields + ['service_id' => $service->id, 'variant_id' => $variant->id]);
        } else {
            $package->forceFill($fields)->save();
        }
        $this->audit->record($before === [] ? 'bookings.package_created' : 'bookings.package_changed', 'service_package', $before, $fields + ['sku' => $variant->sku], $actor, $package->id);

        return $package;
    }
}
