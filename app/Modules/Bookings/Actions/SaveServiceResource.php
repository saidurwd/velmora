<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Actions;

use App\Modules\Bookings\Enums\ResourceType;
use App\Modules\Bookings\Exceptions\BookingException;
use App\Modules\Bookings\Models\ServiceDefinition;
use App\Modules\Bookings\Models\ServiceResource;
use App\Modules\Catalog\Contracts\VariantDirectory;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\DB;

/**
 * Adds or changes who or what can be booked, and which services it serves (named by the SKU of any variant of the service product). Taking a resource out of
 * service does not touch bookings it already has. Who may do this (`bookings.manage`) is decided by the caller.
 */
final class SaveServiceResource
{
    public function __construct(private readonly VariantDirectory $variants, private readonly AuditLogger $audit) {}

    /**
     * @param  list<string>  $serviceSkus
     *
     * @throws BookingException
     */
    public function handle(Actor $actor, ?ServiceResource $resource, string $name, string $type, bool $active, array $serviceSkus): ServiceResource
    {
        $name = mb_substr(trim(BanglaText::input($name)), 0, 120);
        if ($name === '') {
            throw new BookingException('name_required');
        }
        if (ResourceType::tryFrom($type) === null) {
            throw new BookingException('resource_type_invalid');
        }
        $serviceIds = [];
        foreach (array_unique($serviceSkus) as $sku) {
            $variant = $this->variants->findBySku($sku) ?? throw new BookingException('variant_unknown');
            $service = ServiceDefinition::query()->where('product_id', $variant->productId)->first() ?? throw new BookingException('service_not_defined');
            $serviceIds[] = $service->id;
        }

        return Transactions::run(function () use ($actor, $resource, $name, $type, $active, $serviceIds): ServiceResource {
            $before = $resource?->only(['name', 'type', 'is_active']) ?? [];
            $fields = ['name' => $name, 'type' => $type, 'is_active' => $active];
            if ($resource === null) {
                $resource = ServiceResource::query()->create($fields);
            } else {
                $resource->forceFill($fields)->save();
            }
            DB::table('service_resource_links')->where('resource_id', $resource->id)->whereNotIn('service_id', $serviceIds)->delete();
            foreach ($serviceIds as $serviceId) {
                DB::table('service_resource_links')->insertOrIgnore(['service_id' => $serviceId, 'resource_id' => $resource->id]);
            }
            $this->audit->record($before === [] ? 'bookings.resource_created' : 'bookings.resource_changed', $resource, $before, $fields + ['service_ids' => $serviceIds], $actor);

            return $resource;
        });
    }
}
