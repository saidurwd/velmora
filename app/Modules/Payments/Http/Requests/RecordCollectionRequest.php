<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecordCollectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['order_number' => ['required', 'string', 'max:20'], 'collected_on' => ['nullable', 'date_format:Y-m-d'], 'note' => ['nullable', 'string', 'max:255']];
    }
}
