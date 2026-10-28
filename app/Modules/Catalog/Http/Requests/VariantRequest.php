<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Platform\Support\BanglaText;
use Illuminate\Foundation\Http\FormRequest;

/** One sellable variant: SKU, dimensions, purchase limits, option values (attribute value ids). */
class VariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware enforces catalog.* permissions
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'sku' => ['required', 'string', 'max:64'],
            'barcode' => ['nullable', 'string', 'max:64'],
            'track_inventory' => ['sometimes', 'boolean'],
            'weight_g' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'length_mm' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'width_mm' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'height_mm' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'min_qty' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'max_qty' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'qty_step' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'status' => ['nullable', 'in:active,inactive'],
            'is_default' => ['sometimes', 'boolean'],
            'options' => ['nullable', 'array', 'max:10'],
            'options.*' => ['integer'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Bangla digits are accepted in numeric fields
        $numeric = ['weight_g', 'length_mm', 'width_mm', 'height_mm', 'min_qty', 'max_qty', 'qty_step'];
        $this->merge(collect($numeric)->mapWithKeys(fn ($k) => [$k => $this->has($k) ? BanglaText::digitsToAscii((string) $this->input($k)) : null])->filter(fn ($v) => $v !== null)->all());
    }

    public function variant(bool $forceDefault = false): VariantData
    {
        return VariantData::fromInput($this->validated(), $forceDefault);
    }
}
