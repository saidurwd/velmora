<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ImportStatementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['courier' => ['required', 'string', 'max:40'], 'statement' => ['required', 'string', 'max:80'], 'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d'], 'received' => ['required', 'string', 'max:20'], 'file' => ['required', 'file', 'max:2000', 'mimes:csv,txt']];
    }
}
