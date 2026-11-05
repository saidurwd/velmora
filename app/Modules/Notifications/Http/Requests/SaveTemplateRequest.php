<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['locale' => ['required', 'string', 'max:5'], 'subject' => ['nullable', 'string', 'max:400'], 'body' => ['required', 'string', 'max:9000'], 'active' => ['nullable', 'boolean']];
    }
}
