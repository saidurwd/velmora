<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Actions;

use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Platform\Support\Transactions;
use App\Modules\Shipping\Models\ShippingMethod;
use Illuminate\Support\Facades\DB;

/** Creates or edits a delivery method (a way to get the order to the customer, e.g. standard or express). Its code never changes. */
final class SaveShippingMethod
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, array{name?: string|null, delivery_estimate?: string|null}>  $translations  locale => fields
     */
    public function handle(Actor $actor, ?ShippingMethod $method, ?string $code, array $translations, bool $codAvailable, int $codFeeMinor, ?int $freeOverMinor, bool $isActive, int $sortOrder): ShippingMethod
    {
        $before = $method?->only(['cod_available', 'cod_fee_minor', 'free_over_minor', 'is_active', 'sort_order']) ?? [];

        $saved = Transactions::run(function () use ($method, $code, $translations, $codAvailable, $codFeeMinor, $freeOverMinor, $isActive, $sortOrder): ShippingMethod {
            $method ??= new ShippingMethod(['code' => (string) $code]);
            $method->forceFill(['cod_available' => $codAvailable, 'cod_fee_minor' => $codFeeMinor, 'free_over_minor' => $freeOverMinor, 'is_active' => $isActive, 'sort_order' => $sortOrder])->save();

            foreach ($translations as $locale => $fields) {
                $name = trim(BanglaText::input((string) ($fields['name'] ?? '')));
                $estimate = trim(BanglaText::input((string) ($fields['delivery_estimate'] ?? '')));
                if ($name === '') {
                    DB::table('shipping_method_translations')->where('method_id', $method->id)->where('locale', $locale)->delete();

                    continue;
                }
                DB::table('shipping_method_translations')->upsert([['method_id' => $method->id, 'locale' => $locale, 'name' => mb_substr($name, 0, 120), 'delivery_estimate' => $estimate === '' ? null : mb_substr($estimate, 0, 160)]], ['method_id', 'locale'], ['name', 'delivery_estimate']);
            }

            return $method->refresh();
        });

        $this->audit->record($before === [] ? 'shipping.method.created' : 'shipping.method.updated', $saved, $before, $saved->only(['code', 'cod_available', 'cod_fee_minor', 'free_over_minor', 'is_active', 'sort_order']), $actor);

        return $saved;
    }
}
