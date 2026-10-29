<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Http\Requests;

use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Exceptions\MoneyException;
use App\Modules\Platform\Support\BanglaText;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Date;
use Throwable;

/** Shared by coupons and promotions: money in taka, a percent as "7.5", times in Dhaka. */
class CouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') !== null; // the route's permission middleware decides who
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'code' => $this->route('coupon') === null ? ['required', 'string', 'max:64'] : ['prohibited'],
            'description' => ['nullable', 'string', 'max:255'],
            'type' => ['required', 'in:percent,fixed,free_shipping'],
            'value' => ['nullable', 'string', 'max:20'],
            'max_discount' => ['nullable', 'string', 'max:20'],
            'min_spend' => ['nullable', 'string', 'max:20'],
            'scope' => ['required', 'in:all,products,categories'],
            'skus' => ['nullable', 'string', 'max:5000'],
            'categories' => ['nullable', 'array', 'max:200'],
            'categories.*' => ['integer'],
            'starts_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'ends_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'usage_limit' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'per_customer_limit' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['usage_limit', 'per_customer_limit'] as $key) {
            if ($this->has($key)) {
                $this->merge([$key => BanglaText::numeric((string) $this->input($key))]);
            }
        }
        if (is_array($this->input('categories'))) {
            $this->merge(['categories' => array_map(fn ($c) => BanglaText::numeric((string) $c), $this->input('categories'))]);
        }
    }

    /** @return callable[] */
    public function after(): array
    {
        return [function ($validator): void {
            foreach (['max_discount', 'min_spend'] as $field) {
                if ($this->filled($field) && $this->taka((string) $this->input($field)) === null) {
                    $validator->errors()->add($field, __('pricing::promotions.errors.money_invalid'));
                }
            }
            if ($this->input('type') === 'percent' && $this->filled('value') && $this->basisPoints((string) $this->input('value')) === null) {
                $validator->errors()->add('value', __('pricing::promotions.errors.percent_invalid'));
            }
            if ($this->input('type') === 'fixed' && $this->filled('value') && $this->taka((string) $this->input('value')) === null) {
                $validator->errors()->add('value', __('pricing::promotions.errors.money_invalid'));
            }
        }];
    }

    /** Taka as typed ("500", "৫০০.৫০", "1,200") to paisa; null when it is not an amount. */
    public function taka(string $typed): ?int
    {
        try {
            $money = Money::fromMajorString($typed);

            return $money->isNegative() ? null : $money->amountMinor;
        } catch (MoneyException) {
            return null;
        }
    }

    /** "10" or "7.5" percent to basis points (1000, 750); null when not a percentage of 0.01–100. */
    public function basisPoints(string $typed): ?int
    {
        $typed = BanglaText::digitsToAscii(trim($typed));
        if (preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', $typed, $m) !== 1) {
            return null;
        }
        $bp = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');

        return $bp >= 1 && $bp <= 10000 ? $bp : null;
    }

    /** What staff typed in Dhaka time (no time zone in the field) as an instant. */
    public function dhakaTime(?string $typed): ?CarbonImmutable
    {
        if ($typed === null || $typed === '') {
            return null;
        }

        try {
            return Date::parse($typed, 'Asia/Dhaka')->toImmutable()->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<string> */
    public function skuList(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/u', (string) $this->input('skus', '')) ?: []), fn (string $s) => $s !== ''));
    }
}
