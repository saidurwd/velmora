<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** A stock file and what to do with it. The file's own contents are checked row by row by the preview. */
class StockImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:1024', 'extensions:csv,txt'],
            'location' => ['required', 'string', 'max:40'],
            'mode' => ['required', 'in:set,add'],
            'note' => ['required', 'string', 'max:255'],
        ];
    }
}
