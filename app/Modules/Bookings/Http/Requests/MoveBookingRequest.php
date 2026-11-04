<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MoveBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['start' => ['required', 'string', 'max:40'], 'reason' => ['required', 'string', 'max:255']];
    }
}
