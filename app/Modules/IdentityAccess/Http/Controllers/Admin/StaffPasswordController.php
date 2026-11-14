<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Controllers\Admin;

use App\Modules\IdentityAccess\Actions\ChangePassword;
use App\Modules\IdentityAccess\Http\Requests\StaffChangePasswordRequest;
use App\Modules\IdentityAccess\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/** A signed-in staff member changes their own password (Account menu → Change password). Their own account only; no permission is needed. */
class StaffPasswordController
{
    public function edit(): View
    {
        return view('identity-access::admin.password');
    }

    public function update(StaffChangePasswordRequest $request, ChangePassword $change): RedirectResponse
    {
        $user = $request->user('admin');
        abort_unless($user instanceof User, 403);

        // this device stays signed in (its session is the one kept); every other one ends
        $change->handle($user, (string) $request->validated('password'), $request->session()->getId());

        return redirect()->route('admin.account.password')->with('status', __('identity-access::admin.password.changed'));
    }
}
