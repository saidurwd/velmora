<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Requests\Api;

use App\Modules\Shipping\Actions\RequestReturn;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RequestReturnApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route requires the signed-in owner; the Action refuses somebody else's order
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'reason_code' => ['required', 'string', Rule::in(RequestReturn::REASONS)],
            'note' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.position' => ['required', 'integer', 'min:1', 'max:200', 'distinct'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:9999'],
        ];
    }
}
