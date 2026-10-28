<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Controllers\Admin;

use App\Modules\IdentityAccess\Actions\LoginWithPassword;
use App\Modules\IdentityAccess\Enums\AccountType;
use App\Modules\IdentityAccess\Enums\LoginStatus;
use App\Modules\IdentityAccess\Http\Requests\AdminLoginRequest;
use App\Modules\IdentityAccess\Mfa\PendingStaffLogin;
use App\Modules\IdentityAccess\Models\StaffMfaCredential;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminAuthController
{
    public function showLogin(): View
    {
        return view('identity-access::admin.login');
    }

    /** Step 1 of 2. A correct password signs nobody in: it only unlocks the MFA step. */
    public function login(AdminLoginRequest $request, LoginWithPassword $login, PendingStaffLogin $pending): RedirectResponse
    {
        $outcome = $login->handle((string) $request->validated('email'), (string) $request->validated('password'), $request->ip(), AccountType::Staff);

        if ($outcome->status === LoginStatus::Throttled) {
            return back()->withInput($request->only('email'))->withErrors(['email' => __('auth.throttle', ['seconds' => $outcome->retryAfterSeconds])]);
        }

        if (! $outcome->succeeded() || $outcome->user === null) {
            return back()->withInput($request->only('email'))->withErrors(['email' => __('auth.failed')]);
        }

        $request->session()->regenerate(); // new session id before any privileged state is stored
        $pending->start($outcome->user->id);

        $enrolled = StaffMfaCredential::query()->where('user_id', $outcome->user->id)->whereNotNull('confirmed_at')->exists();

        return redirect()->route($enrolled ? 'admin.mfa.challenge' : 'admin.mfa.enrol');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
