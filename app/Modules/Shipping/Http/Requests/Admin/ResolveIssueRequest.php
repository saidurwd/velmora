<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ResolveIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['outcome' => ['required', 'in:resolved,ignored'], 'note' => ['required', 'string', 'max:255']];
    }
}
