<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\Web;

use App\Modules\Customers\Contracts\CustomerProfiles;
use App\Modules\Customers\Http\Requests\ProfileRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProfileController
{
    public function show(Request $request, CustomerProfiles $profiles): View
    {
        return view('customers::account.profile', ['profile' => $profiles->findByUserId((int) $request->user('web')?->getAuthIdentifier())]);
    }

    public function update(ProfileRequest $request, CustomerProfiles $profiles): RedirectResponse
    {
        $user = $request->user('web');
        $userId = (int) $user?->getAuthIdentifier();

        // The profile row is created from an outbox event; make sure it exists even if that has not been delivered yet.
        $profiles->ensureForUser($userId, null, $user?->email, $user?->phone);
        $profiles->updateProfile($userId, (string) $request->validated('name'), $request->validated('birth_date'), $request->boolean('marketing_consent'));

        return back()->with('status', __('customers::account.profile.saved'));
    }
}
