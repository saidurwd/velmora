<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Attribute definition. Values arrive as one line each: `code | English label | Bangla label`. */
class AttributeRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_-]+$/i'],
            'kind' => ['required', 'in:option,spec'],
            'is_filterable' => ['sometimes', 'boolean'],
            'values' => ['nullable', 'string', 'max:20000'],
            "names.{$default}" => ['required', 'string', 'max:160'],
        ];

        foreach ((array) config('app.supported_locales') as $locale) {
            $rules["names.{$locale}"] ??= ['nullable', 'string', 'max:160'];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function names(): array
    {
        return array_map('strval', (array) $this->validated('names', []));
    }

    /** @return list<array{code: string, labels: array<string, string>}> */
    public function valueRows(): array
    {
        $locales = array_values((array) config('app.supported_locales')); // default first: en, bn
        $rows = [];

        foreach (preg_split('/\R/u', (string) $this->validated('values', '')) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            $code = array_shift($parts);
            $labels = [];
            foreach ($locales as $i => $locale) {
                $labels[(string) $locale] = $parts[$i] ?? '';
            }
            $rows[] = ['code' => (string) $code, 'labels' => $labels];
        }

        return $rows;
    }
}
