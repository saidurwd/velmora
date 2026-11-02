<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Actions;

use App\Modules\Catalog\Contracts\VariantDirectory;
use App\Modules\DigitalProducts\Exceptions\DigitalException;
use App\Modules\DigitalProducts\Models\RefundPolicy;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Chooses the refund policy of one digital product (or hands it back to the shop's default with an empty choice). */
final class SetAssetRefundPolicy
{
    public function __construct(private readonly VariantDirectory $variants, private readonly AuditLogger $audit, private readonly Clock $clock) {}

    /** @throws DigitalException */
    public function handle(Actor $actor, string $sku, ?string $policyPublicId): void
    {
        $variant = $this->variants->findBySku($sku) ?? throw new DigitalException('variant_unknown');
        if ($variant->productType !== 'digital') {
            throw new DigitalException('variant_not_digital');
        }
        $policyId = null;
        if ($policyPublicId !== null && $policyPublicId !== '') {
            $policyId = RefundPolicy::query()->where('public_id', $policyPublicId)->value('id') ?? throw new DigitalException('policy_unknown');
        }

        DB::table('digital_assets')->insertOrIgnore(['public_id' => strtolower((string) Str::ulid()), 'variant_id' => $variant->id, 'created_by' => $actor->id, 'created_at' => $this->clock->now(), 'updated_at' => $this->clock->now()]);
        $before = DB::table('digital_assets')->where('variant_id', $variant->id)->value('refund_policy_id');
        DB::table('digital_assets')->where('variant_id', $variant->id)->update(['refund_policy_id' => $policyId, 'updated_at' => $this->clock->now()]);
        $this->audit->record('digital.asset_policy_set', 'digital_asset', ['refund_policy_id' => $before], ['refund_policy_id' => $policyId, 'sku' => $variant->sku], $actor, $variant->id);
    }
}
