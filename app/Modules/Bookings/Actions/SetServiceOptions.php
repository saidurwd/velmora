<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Actions;

use App\Modules\Bookings\Exceptions\BookingException;
use App\Modules\Bookings\Models\ServiceDefinition;
use App\Modules\Catalog\Contracts\VariantDirectory;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use Illuminate\Support\Facades\DB;

/** Two optional things of a service that is already bookable: which cancellation policy it follows (none = the shop's default) and, for an online service, where the customer joins. */
final class SetServiceOptions
{
    public function __construct(private readonly VariantDirectory $variants, private readonly AuditLogger $audit) {}

    /** @throws BookingException service_not_defined | policy_unknown | meeting_url_invalid */
    public function handle(Actor $actor, string $sku, ?string $policyPublicId, ?string $meetingUrl): ServiceDefinition
    {
        $variant = $this->variants->findBySku($sku) ?? throw new BookingException('variant_unknown');
        $service = ServiceDefinition::query()->where('product_id', $variant->productId)->first() ?? throw new BookingException('service_not_defined');

        $policyId = null;
        if ($policyPublicId !== null && $policyPublicId !== '') {
            $policyId = DB::table('cancellation_policies')->where('public_id', $policyPublicId)->value('id') ?? throw new BookingException('policy_unknown');   // unique public_id
        }

        $url = $meetingUrl === null ? '' : trim($meetingUrl);
        if ($url !== '') {
            $parts = parse_url($url);
            if (mb_strlen($url) > 500 || ! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
                throw new BookingException('meeting_url_invalid');   // only a plain https address: never a script, a file or a plain-http link
            }
        }

        $before = ['cancellation_policy_id' => $service->cancellation_policy_id, 'meeting_url' => $service->meeting_url];
        $service->forceFill(['cancellation_policy_id' => $policyId === null ? null : (int) $policyId, 'meeting_url' => $url === '' ? null : $url])->save();
        $this->audit->record('bookings.service_options_changed', $service, $before, ['cancellation_policy_id' => $service->cancellation_policy_id, 'meeting_url' => $service->meeting_url, 'sku' => $sku], $actor);

        return $service;
    }
}
