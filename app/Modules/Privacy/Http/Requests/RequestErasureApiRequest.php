<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RequestErasureApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route requires a signed-in customer with a fresh confirmation
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:500'], 'understand' => ['required', 'accepted']];
    }
}
