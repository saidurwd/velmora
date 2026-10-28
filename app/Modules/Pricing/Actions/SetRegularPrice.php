<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Actions;

use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Transactions;
use App\Modules\Pricing\Exceptions\PricingException;
use Illuminate\Support\Facades\DB;

final class SetRegularPrice
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Actor $actor, int $variantId, Money $amount, ?Money $compareAt = null, string $listCode = 'retail'): void
    {
        $list = DB::table('price_lists')->where('code', $listCode)->first() ?? throw PricingException::unknownList($listCode);

        foreach ([$amount, $compareAt] as $money) {
            if ($money !== null && $money->currency !== $list->currency) {
                throw PricingException::currencyMismatch((string) $list->currency, $money->currency);
            }
        }

        if ($amount->isNegative()) {
            throw PricingException::compareAtTooLow();
        }

        if ($compareAt !== null && $compareAt->amountMinor <= $amount->amountMinor) {
            throw PricingException::compareAtTooLow();
        }

        Transactions::run(function () use ($actor, $variantId, $amount, $compareAt, $list): void {
            $before = DB::table('prices')->where('variant_id', $variantId)->where('price_list_id', $list->id)->first();
            $now = now('UTC')->format('Y-m-d H:i:s.u');

            DB::table('prices')->updateOrInsert(
                ['variant_id' => $variantId, 'price_list_id' => $list->id],
                ['amount_minor' => $amount->amountMinor, 'compare_at_minor' => $compareAt?->amountMinor, 'currency' => $amount->currency, 'updated_at' => $now] + ($before === null ? ['created_at' => $now] : []),
            );

            $this->audit->record('price.set', 'variant', $before === null ? [] : ['amount_minor' => $before->amount_minor, 'compare_at_minor' => $before->compare_at_minor],
                ['amount_minor' => $amount->amountMinor, 'compare_at_minor' => $compareAt?->amountMinor, 'list' => $list->code], $actor, $variantId);
        });
    }
}
