<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Controllers\Web;

use App\Modules\Cms\Actions\AcceptPolicies;
use App\Modules\Cms\Contracts\PolicyAcceptances;
use App\Modules\Cms\Exceptions\PolicyException;
use App\Modules\Cms\Http\Requests\AcceptPoliciesRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PolicyController
{
    public function show(Request $request, PolicyAcceptances $policies): View
    {
        return view('cms::policies.account', ['notices' => $policies->outstanding((int) $request->user('web')?->getAuthIdentifier(), 'signup', null, app()->getLocale())]);
    }

    public function accept(AcceptPoliciesRequest $request, AcceptPolicies $accept): RedirectResponse
    {
        try {
            $accept->handle((int) $request->user('web')?->getAuthIdentifier(), $request->releases());
        } catch (PolicyException $e) {
            return redirect()->route('account.policies', ['locale' => app()->getLocale()])->withErrors(['policies' => __('cms::policies.errors.'.$e->reason)]);
        }

        return redirect()->route('account.policies', ['locale' => app()->getLocale()])->with('status', __('cms::policies.account.accepted'));
    }
}
