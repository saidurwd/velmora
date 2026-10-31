<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePaymentSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['refund_limit' => ['required', 'string', 'max:15', 'regex:/^[0-9০-৯]+([.][0-9০-৯]{1,2})?$/u'], 'methods' => ['sometimes', 'array'], 'methods.*' => ['boolean']];
    }
}
