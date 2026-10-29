<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Requests;

use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Exceptions\MoneyException;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Shipping\DTOs\RateBand;
use Illuminate\Foundation\Http\FormRequest;

class ZoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $default = (string) config('app.default_locale');

        return [
            'translations' => ['required', 'array'],
            "translations.{$default}.name" => ['required', 'string', 'max:120'],
            'translations.*.name' => ['nullable', 'string', 'max:120'],
            'is_fallback' => ['sometimes', 'boolean'],
            'cod_available' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'districts' => ['nullable', 'array', 'max:70'],
            'districts.*' => ['integer'],
            'rates' => ['nullable', 'array'],
            'rates.*' => ['nullable', 'array', 'max:12'],
            'rates.*.*.weight' => ['nullable', 'string', 'max:12'],
            'rates.*.*.price' => ['nullable', 'string', 'max:20'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['sort_order'] as $key) {
            if ($this->has($key)) {
                $this->merge([$key => BanglaText::numeric((string) $this->input($key))]);
            }
        }
        if (is_array($this->input('districts'))) {
            $this->merge(['districts' => array_map(fn ($d) => BanglaText::numeric((string) $d), $this->input('districts'))]);
        }
    }

    /** @return array<string, string|null> */
    public function names(): array
    {
        return array_map(fn ($t) => $t['name'] ?? null, (array) $this->validated('translations', []));
    }

    /** @return list<int> */
    public function districtIds(): array
    {
        return array_values(array_map('intval', (array) $this->validated('districts', [])));
    }

    /**
     * Rows left blank are ignored. A row with a price and no weight is the "any heavier parcel" band.
     *
     * @param  array<string, int>  $methodIds  method public id => id (only methods that exist are accepted)
     * @return array<int, list<RateBand>>
     */
    public function rates(array $methodIds): array
    {
        $out = [];
        foreach ((array) $this->input('rates', []) as $publicId => $rows) {
            $methodId = $methodIds[(string) $publicId] ?? null;
            if ($methodId === null) {
                continue;
            }

            $bands = [];
            foreach ((array) $rows as $row) {
                $price = trim((string) ($row['price'] ?? ''));
                $weight = trim((string) ($row['weight'] ?? ''));
                if ($price === '' && $weight === '') {
                    continue;
                }
                $bands[] = new RateBand($weight === '' ? null : $this->grams($weight), $this->minor($price));
            }
            $out[$methodId] = $bands;
        }

        return $out;
    }

    /** Kilograms as typed ("0.5", "২") to grams; -1 marks something that is not a weight (refused as invalid). */
    private function grams(string $kg): int
    {
        $kg = BanglaText::digitsToAscii($kg);
        if (preg_match('/^(\d+)(?:\.(\d{1,3}))?$/', $kg, $m) !== 1) {
            return -1;
        }

        return (int) $m[1] * 1000 + (int) str_pad($m[2] ?? '', 3, '0');
    }

    private function minor(string $taka): int
    {
        try {
            return Money::fromMajorString($taka)->amountMinor;
        } catch (MoneyException) {
            return -1;
        }
    }
}
