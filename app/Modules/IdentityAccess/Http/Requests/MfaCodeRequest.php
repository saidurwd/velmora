<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Requests;

use App\Modules\Platform\Support\BanglaText;
use Illuminate\Foundation\Http\FormRequest;

class MfaCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['code' => BanglaText::numeric(BanglaText::input((string) $this->input('code')))]);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['code' => ['required', 'string', 'min:6', 'max:12']];
    }
}
