<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Requests\Concerns;

use App\Modules\Cms\Contracts\PolicyAcceptances;

/** When any policy has been published, creating an account needs the "I accept" box and the release ids the page showed. Nothing published: nothing extra. */
trait AsksForPolicies
{
    /** @return array<string, array<int, mixed>> */
    protected function policyRules(): array
    {
        if (app(PolicyAcceptances::class)->outstanding(null, 'signup', null, app()->getLocale()) === []) {
            return [];
        }

        return ['accept_policies' => ['accepted'], 'policy_releases' => ['required', 'array', 'max:10'], 'policy_releases.*' => ['integer', 'min:1']];
    }

    /** @return array<string, string> */
    protected function policyMessages(): array
    {
        return ['accept_policies.accepted' => __('cms::policies.errors.policies_required'), 'policy_releases.required' => __('cms::policies.errors.policies_required')];
    }

    /** @return list<int> */
    public function policyReleases(): array
    {
        return array_values(array_map('intval', array_filter((array) $this->input('policy_releases', []), 'is_numeric')));
    }
}
