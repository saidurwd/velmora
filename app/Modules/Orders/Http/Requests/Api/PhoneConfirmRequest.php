<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests\Api;

use App\Modules\Platform\Support\BanglaText;

class PhoneConfirmRequest extends PhoneCodeRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['code' => BanglaText::numeric((string) $this->input('code'))]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return parent::rules() + ['code' => ['required', 'digits:6']];
    }
}
