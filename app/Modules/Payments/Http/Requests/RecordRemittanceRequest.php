<?php

declare(strict_types=1);

namespace App\Modules\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecordRemittanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['courier' => ['required', 'string', 'max:40'], 'statement' => ['required', 'string', 'max:80'], 'received' => ['required', 'string', 'max:15', 'regex:/^[0-9০-৯]+([.][0-9০-৯]{1,2})?$/u'], 'received_on' => ['nullable', 'date_format:Y-m-d'], 'parcels' => ['required', 'array', 'min:1', 'max:500'], 'parcels.*' => ['string', 'size:26'], 'amounts' => ['required', 'array'], 'amounts.*' => ['required', 'string', 'max:15', 'regex:/^[0-9০-৯]+([.][0-9০-৯]{1,2})?$/u']];
    }
}
