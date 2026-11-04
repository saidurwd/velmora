<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'location' => ['required', 'string', 'in:at_provider,online'], 'lead_time_min' => ['required', 'integer', 'min:0', 'max:525600'], 'max_horizon_days' => ['required', 'integer', 'min:1', 'max:365'],
            'hold_minutes' => ['required', 'integer', 'min:5', 'max:120'], 'active' => ['nullable', 'boolean'], 'duration_min' => ['required', 'integer', 'min:5', 'max:1440'],
            'buffer_before_min' => ['required', 'integer', 'min:0', 'max:240'], 'buffer_after_min' => ['required', 'integer', 'min:0', 'max:240'], 'package_active' => ['nullable', 'boolean'],
            'policy' => ['nullable', 'string', 'size:26', 'alpha_num'], 'meeting_url' => ['nullable', 'string', 'max:500'],
        ];
    }
}
