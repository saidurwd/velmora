<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\DTOs\TranslationInput;
use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Pricing\Contracts\TaxRates;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Product details: type, bilingual content, classification. The first variant is entered on creation only. */
class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route middleware enforces catalog.* permissions
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $default = (string) config('app.default_locale');
        $rules = [
            'product_type' => ['required', Rule::enum(ProductType::class)],
            'brand' => ['nullable', 'string', 'size:26'],
            'tax_class_code' => ['required', Rule::in(app(TaxRates::class)->classCodes())],
            'categories' => ['nullable', 'array', 'max:20'],
            'categories.*' => ['string', 'size:26'],
            'primary_category' => ['nullable', 'string', 'size:26'],
            'tags' => ['nullable', 'array', 'max:50'],
            'tags.*' => ['string', 'size:26'],
            "translations.{$default}.name" => ['required', 'string', 'max:200'],
        ];

        foreach ((array) config('app.supported_locales') as $locale) {
            $rules["translations.{$locale}.name"] ??= ['nullable', 'string', 'max:200'];
            $rules["translations.{$locale}.slug"] = ['nullable', 'string', 'max:160'];
            $rules["translations.{$locale}.short_description"] = ['nullable', 'string', 'max:500'];
            $rules["translations.{$locale}.description"] = ['nullable', 'string', 'max:50000'];
            $rules["translations.{$locale}.seo_title"] = ['nullable', 'string', 'max:160'];
            $rules["translations.{$locale}.seo_description"] = ['nullable', 'string', 'max:320'];
        }

        if ($this->isMethod('POST')) {
            $rules += (new VariantRequest)->rules();
        }

        return $rules;
    }

    public function variant(): VariantData
    {
        return VariantData::fromInput($this->validated(), true);
    }

    public function type(): ProductType
    {
        return ProductType::from((string) $this->validated('product_type'));
    }

    /** @return list<TranslationInput> */
    public function translations(): array
    {
        /** @var array<string, array<string, mixed>> $input */
        $input = (array) $this->validated('translations', []);

        return TranslationInput::fromArray($input);
    }
}
