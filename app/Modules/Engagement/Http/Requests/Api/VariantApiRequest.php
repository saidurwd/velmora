<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class VariantApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route requires a signed-in customer; the list is always theirs
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['variant' => ['required', 'string', 'size:26']];
    }
}
