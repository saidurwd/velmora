<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // the size and the real type are checked again by the Action, from the file's own bytes
        return ['file' => ['required', 'file', 'max:'.((int) config('digital.max_upload_mb') * 1024)]];
    }
}
