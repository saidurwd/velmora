<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Actions;

use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Platform\Support\Transactions;
use App\Modules\Pricing\Exceptions\PromotionException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** An automatic promotion: applied to a cart with no code when it qualifies. When several qualify, the best one wins (see the evaluator). */
final class SavePromotion
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  'percent'|'fixed'  $type
     * @param  array<string, string|null>  $labels  locale => label
     *
     * @throws PromotionException
     */
    public function handle(Actor $actor, ?int $promotionId, string $type, int $value, ?int $maxDiscountMinor, int $minSpendMinor, ?CarbonImmutable $startsAt, ?CarbonImmutable $endsAt, bool $isActive, array $labels): int
    {
        if (($type === 'percent' && ($value < 1 || $value > 10000)) || ($type === 'fixed' && $value < 1)) {
            throw new PromotionException($type === 'percent' ? 'coupon_percent_range' : 'coupon_amount_required');
        }
        if ($minSpendMinor < 0 || ($maxDiscountMinor !== null && $maxDiscountMinor < 1)) {
            throw new PromotionException('coupon_numbers_invalid');
        }
        if ($startsAt !== null && $endsAt !== null && ! $endsAt->greaterThan($startsAt)) {
            throw new PromotionException('window_invalid');
        }

        $id = Transactions::run(function () use ($promotionId, $type, $value, $maxDiscountMinor, $minSpendMinor, $startsAt, $endsAt, $isActive, $labels): int {
            $now = now('UTC');
            $row = [
                'type' => $type, 'value' => $value, 'max_discount_minor' => $type === 'percent' ? $maxDiscountMinor : null, 'min_spend_minor' => $minSpendMinor,
                'starts_at' => $startsAt?->utc()->format('Y-m-d H:i:s.u'), 'ends_at' => $endsAt?->utc()->format('Y-m-d H:i:s.u'), 'is_active' => $isActive, 'updated_at' => $now,
            ];

            if ($promotionId === null) {
                $promotionId = (int) DB::table('promotions')->insertGetId($row + ['public_id' => strtolower((string) Str::ulid()), 'created_at' => $now]);
            } else {
                DB::table('promotions')->where('id', $promotionId)->update($row);
            }

            foreach ($labels as $locale => $label) {
                $label = trim(BanglaText::input((string) $label));
                if ($label === '') {
                    DB::table('promotion_translations')->where('promotion_id', $promotionId)->where('locale', $locale)->delete();

                    continue;
                }
                DB::table('promotion_translations')->upsert([['promotion_id' => $promotionId, 'locale' => $locale, 'label' => mb_substr($label, 0, 160)]], ['promotion_id', 'locale'], ['label']);
            }

            return $promotionId;
        });

        $this->audit->record($promotionId === null ? 'promotion.created' : 'promotion.saved', 'promotion', [], ['type' => $type, 'value' => $value, 'is_active' => $isActive], $actor, $id);

        return $id;
    }
}
