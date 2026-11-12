<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Requests\Api;

use App\Modules\Platform\Support\BanglaText;

/** The phone code, plus the device, plus (only needed when this first sign-in creates the account) the policies the person accepted. */
class OtpVerifyRequest extends OtpRequestRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['code' => BanglaText::numeric((string) $this->input('code'))]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return parent::rules() + [
            'code' => ['required', 'digits:6'],
            'device_name' => ['nullable', 'string', 'max:80'],
            'platform' => ['nullable', 'in:android,ios'],
            'policy_releases' => ['nullable', 'array', 'max:10'],
            'policy_releases.*' => ['integer', 'min:1'],
        ];
    }

    /** @return list<int> */
    public function policyReleases(): array
    {
        return array_values(array_map('intval', array_filter((array) $this->input('policy_releases', []), 'is_numeric')));
    }
}
