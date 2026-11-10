<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Requests;

use App\Modules\Payments\Enums\RefundReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RequestRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['amount' => ['required', 'string', 'max:15', 'regex:/^[0-9০-৯]+([.][0-9০-৯]{1,2})?$/u'], 'reason' => ['required', 'string', 'max:255'], 'reason_code' => ['required', 'string', Rule::in(RefundReason::values())], 'attempt' => ['nullable', 'string', 'size:26'], 'manual' => ['sometimes', 'boolean']];
    }
}
