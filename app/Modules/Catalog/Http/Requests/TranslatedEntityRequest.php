<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\DTOs\TranslationInput;
use Illuminate\Foundation\Http\FormRequest;

/** Shared by categories, brands, tags and collections: bilingual content tabs + ordering + active flag. */
class TranslatedEntityRequest extends FormRequest
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
            "translations.{$default}.name" => ['required', 'string', 'max:160'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
            'parent' => ['nullable', 'string', 'size:26'],
        ];

        foreach ((array) config('app.supported_locales') as $locale) {
            $rules["translations.{$locale}.name"] ??= ['nullable', 'string', 'max:160'];
            $rules["translations.{$locale}.slug"] = ['nullable', 'string', 'max:120'];
            $rules["translations.{$locale}.description"] = ['nullable', 'string', 'max:20000'];
            $rules["translations.{$locale}.seo_title"] = ['nullable', 'string', 'max:160'];
            $rules["translations.{$locale}.seo_description"] = ['nullable', 'string', 'max:320'];
        }

        return $rules;
    }

    /** @return list<TranslationInput> */
    public function translations(): array
    {
        return TranslationInput::fromArray((array) $this->validated('translations', []));
    }
}
