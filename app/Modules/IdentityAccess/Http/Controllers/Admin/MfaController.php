<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Controllers\Admin;

use App\Modules\IdentityAccess\Actions\BeginMfaEnrolment;
use App\Modules\IdentityAccess\Actions\ConfirmMfaEnrolment;
use App\Modules\IdentityAccess\Actions\VerifyStaffMfa;
use App\Modules\IdentityAccess\Enums\MfaResult;
use App\Modules\IdentityAccess\Http\Requests\MfaCodeRequest;
use App\Modules\IdentityAccess\Mfa\PendingStaffLogin;
use App\Modules\IdentityAccess\Models\StaffMfaCredential;
use App\Modules\IdentityAccess\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MfaController
{
    public function __construct(private readonly PendingStaffLogin $pending) {}

    public function showEnrol(BeginMfaEnrolment $begin): View|RedirectResponse
    {
        $staff = $this->pendingUser();

        if ($staff === null) {
            return redirect()->route('admin.login');
        }

        if (StaffMfaCredential::query()->where('user_id', $staff->id)->whereNotNull('confirmed_at')->exists()) {
            return redirect()->route('admin.mfa.challenge');
        }

        return view('identity-access::admin.mfa-enrol', $begin->handle($staff));
    }

    public function enrol(MfaCodeRequest $request, ConfirmMfaEnrolment $confirm): RedirectResponse
    {
        $staff = $this->pendingUser();

        if ($staff === null) {
            return redirect()->route('admin.login');
        }

        $codes = $confirm->handle($staff, (string) $request->validated('code'));

        if ($codes === null) {
            return back()->withErrors(['code' => __('identity-access::admin.mfa.invalid')]);
        }

        $this->signIn($request, $staff);

        return redirect()->route('admin.mfa.recovery')->with('recovery_codes', $codes);
    }

    /** Shown exactly once, right after enrolment. */
    public function showRecovery(Request $request): View|RedirectResponse
    {
        $codes = $request->session()->get('recovery_codes');

        return is_array($codes) ? view('identity-access::admin.recovery-codes', ['codes' => $codes]) : redirect()->route('admin.dashboard');
    }

    public function showChallenge(): View|RedirectResponse
    {
        return $this->pendingUser() === null ? redirect()->route('admin.login') : view('identity-access::admin.mfa-challenge');
    }

    public function challenge(MfaCodeRequest $request, VerifyStaffMfa $verify): RedirectResponse
    {
        $staff = $this->pendingUser();

        if ($staff === null) {
            return redirect()->route('admin.login');
        }

        $result = $verify->handle($staff, (string) $request->validated('code'));

        if ($result === MfaResult::Locked) {
            $this->pending->clear();

            return redirect()->route('admin.login')->withErrors(['email' => __('identity-access::admin.mfa.locked')]);
        }

        if ($result === MfaResult::Invalid) {
            return back()->withErrors(['code' => __('identity-access::admin.mfa.invalid')]);
        }

        $this->signIn($request, $staff);

        if ($result === MfaResult::RecoveryUsed) {
            return redirect()->route('admin.dashboard')->with('status', __('identity-access::admin.mfa.recovery_used'));
        }

        return redirect()->intended(route('admin.dashboard'));
    }

    private function pendingUser(): ?User
    {
        $id = $this->pending->userId();

        return $id === null ? null : User::query()->where('account_type', 'staff')->whereKey($id)->first();
    }

    private function signIn(Request $request, User $staff): void
    {
        $this->pending->clear();
        Auth::guard('admin')->login($staff); // rotates the session id; the device record picks up the new one
    }
}
