<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RevokeEntitlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:255']];
    }
}
