<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DecideErasureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // Rejecting needs a reason the customer is told; approving may carry a short note.
        return ['reason' => [$this->routeIs('admin.privacy.reject') ? 'required' : 'nullable', 'string', 'max:255']];
    }
}
