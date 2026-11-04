<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['date' => ['required', 'string', 'max:10'], 'name' => ['required', 'string', 'max:120']];
    }
}
