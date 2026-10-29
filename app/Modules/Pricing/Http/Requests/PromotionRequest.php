<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Http\Requests;

class PromotionRequest extends CouponRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $default = (string) config('app.default_locale');

        return [
            'type' => ['required', 'in:percent,fixed'],
            'value' => ['required', 'string', 'max:20'],
            'max_discount' => ['nullable', 'string', 'max:20'],
            'min_spend' => ['nullable', 'string', 'max:20'],
            'starts_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'ends_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'is_active' => ['sometimes', 'boolean'],
            'translations' => ['required', 'array'],
            "translations.{$default}.label" => ['required', 'string', 'max:160'],
            'translations.*.label' => ['nullable', 'string', 'max:160'],
        ];
    }

    /** @return array<string, string|null> */
    public function labels(): array
    {
        return array_map(fn ($t) => $t['label'] ?? null, (array) $this->validated('translations', []));
    }
}
