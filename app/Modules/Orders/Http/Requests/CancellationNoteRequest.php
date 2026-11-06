<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CancellationNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web') !== null || $this->user('admin') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // Refusing needs the reason the customer is told; everything else takes an optional note.
        return ['note' => [$this->routeIs('admin.orders.cancellations.reject') ? 'required' : 'nullable', 'string', 'max:255']];
    }
}
