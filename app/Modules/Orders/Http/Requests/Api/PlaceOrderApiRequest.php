<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests\Api;

/** Everything the quote takes, plus the total the person was shown, the policies they accepted and (for a large cash order) the proof their phone was confirmed. */
class PlaceOrderApiRequest extends CheckoutRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return parent::rules() + [
            'payment_method' => ['required', 'string', 'max:30', 'regex:/^[a-z0-9_-]+$/'],
            'expected_total' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'accept_policies' => ['nullable', 'boolean'],
            'policy_releases' => ['nullable', 'array', 'max:10'],
            'policy_releases.*' => ['integer', 'min:1'],
            'phone_proof' => ['nullable', 'string', 'max:300'],
        ];
    }

    public function expectedTotal(): int
    {
        return (int) $this->validated('expected_total');
    }

    /** @return list<int> */
    public function policyReleases(): array
    {
        return $this->boolean('accept_policies') ? array_values(array_map('intval', (array) $this->validated('policy_releases', []))) : [];
    }

    public function phoneProof(): ?string
    {
        return $this->validated('phone_proof');
    }
}
