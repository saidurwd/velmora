<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Controllers\Web;

use App\Modules\IdentityAccess\Actions\ChangeEmail;
use App\Modules\IdentityAccess\Actions\ChangePassword;
use App\Modules\IdentityAccess\Actions\ChangePhone;
use App\Modules\IdentityAccess\Actions\ChangePhoneResult;
use App\Modules\IdentityAccess\Actions\ConfirmRecentAuth;
use App\Modules\IdentityAccess\Auth\RecentAuth;
use App\Modules\IdentityAccess\Enums\OtpIssueStatus;
use App\Modules\IdentityAccess\Http\Requests\ChangeEmailRequest;
use App\Modules\IdentityAccess\Http\Requests\ChangePasswordRequest;
use App\Modules\IdentityAccess\Http\Requests\PhoneRequest;
use App\Modules\IdentityAccess\Http\Requests\VerifyOtpRequest;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\IdentityAccess\Sessions\UserSessionRegistry;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\PhoneNumber;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Support\AuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AccountSecurityController
{
    private const PENDING_PHONE = 'account.pending_phone';

    // --- re-authentication -------------------------------------------------------------------------------------

    public function showConfirm(Request $request): View
    {
        return view('identity-access::account.confirm', ['hasPassword' => $this->user($request)->password !== null, 'hasPhone' => $this->user($request)->phone_verified_at !== null]);
    }

    public function confirmWithPassword(Request $request, ConfirmRecentAuth $confirm, RecentAuth $recent): RedirectResponse
    {
        $request->validate(['password' => ['required', 'string', 'max:128']]);

        if (! $confirm->withPassword($this->user($request), (string) $request->input('password'))) {
            return back()->withErrors(['password' => __('auth.password')]);
        }

        $recent->mark();

        return redirect()->intended(route('account.security'));
    }

    public function sendConfirmOtp(Request $request, ConfirmRecentAuth $confirm): RedirectResponse
    {
        $confirm->requestOtp($this->user($request), $request->ip(), app()->getLocale());

        return back()->with('status', __('identity-access::account.confirm.code_sent'));
    }

    public function confirmWithOtp(VerifyOtpRequest $request, ConfirmRecentAuth $confirm, RecentAuth $recent): RedirectResponse
    {
        if (! $confirm->withOtp($this->user($request), (string) $request->validated('code'))) {
            return back()->withErrors(['code' => __('identity-access::auth.otp.invalid')]);
        }

        $recent->mark();

        return redirect()->intended(route('account.security'));
    }

    // --- security page --------------------------------------------------------------------------------------------

    public function show(Request $request, UserSessionRegistry $registry): View
    {
        $user = $this->user($request);

        return view('identity-access::account.security', [
            'user' => $user,
            'hasPassword' => $user->password !== null,
            'pendingPhone' => $request->session()->get(self::PENDING_PHONE),
            'devices' => count(array_filter($registry->listFor($user->id), fn (object $s) => ($s->guard ?? null) === 'web')),
        ]);
    }

    public function changePassword(ChangePasswordRequest $request, ChangePassword $action): RedirectResponse
    {
        $action->handle($this->user($request), (string) $request->validated('password'), $request->session()->getId());

        return back()->with('status', __('identity-access::account.password.changed'));
    }

    public function requestEmail(ChangeEmailRequest $request, ChangeEmail $action): RedirectResponse
    {
        $action->request($this->user($request), (string) $request->validated('email'));

        return back()->with('status', __('identity-access::account.email.sent')); // identical whether or not the address is taken
    }

    /** Signed link from the new address; the person must be signed in as the account owner. */
    public function confirmEmail(Request $request, string $user, ChangeEmail $action): RedirectResponse
    {
        $me = $this->user($request);
        $new = (string) base64_decode(strtr((string) $request->query('new'), '-_', '+/'), true);

        if ($me->public_id !== $user || $new === '' || ! $action->confirm($me, $new, (string) $request->query('old'))) {
            return redirect()->route('account.security')->withErrors(['email' => __('identity-access::account.email.invalid_link')]);
        }

        return redirect()->route('account.security')->with('status', __('identity-access::account.email.changed'));
    }

    public function requestPhone(PhoneRequest $request, ChangePhone $action): RedirectResponse
    {
        $phone = $request->phone();
        $status = $action->request($phone, $request->ip(), app()->getLocale());

        if ($status !== OtpIssueStatus::Sent && $status !== OtpIssueStatus::CoolingDown) {
            return back()->withErrors(['phone' => __('identity-access::auth.otp.limited')]);
        }

        $request->session()->put(self::PENDING_PHONE, $phone->e164());

        return back()->with('status', __('identity-access::account.phone.code_sent', ['phone' => $phone->masked()]));
    }

    public function confirmPhone(VerifyOtpRequest $request, ChangePhone $action): RedirectResponse
    {
        $e164 = $request->session()->get(self::PENDING_PHONE);
        $phone = is_string($e164) ? PhoneNumber::tryParse($e164) : null;

        if ($phone === null) {
            return back()->withErrors(['code' => __('identity-access::auth.otp.invalid')]);
        }

        $result = $action->confirm($this->user($request), $phone, (string) $request->validated('code'));

        if ($result === ChangePhoneResult::InvalidCode) {
            return back()->withErrors(['code' => __('identity-access::auth.otp.invalid')]);
        }

        $request->session()->forget(self::PENDING_PHONE);

        return $result === ChangePhoneResult::AlreadyUsed
            ? back()->withErrors(['phone' => __('identity-access::account.phone.already_used')])
            : back()->with('status', __('identity-access::account.phone.changed'));
    }

    public function revokeOtherDevices(Request $request, UserSessionRegistry $registry): RedirectResponse
    {
        $count = $registry->revokeOthers($this->user($request)->id, $request->session()->getId(), 'web');

        return back()->with('status', __('identity-access::account.sessions.revoked', ['count' => $count]));
    }

    /** Stub: records the request. The review/anonymisation workflow arrives with the privacy tools in Phase 9. */
    public function requestDeletion(Request $request, AuditLogger $audit): RedirectResponse
    {
        $user = $this->user($request);
        $audit->record('account.deletion_requested', $user, actor: new Actor(ActorType::Customer, $user->id, $user->public_id));

        return back()->with('status', __('identity-access::account.delete.requested'));
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = Auth::guard('web')->user();

        return $user;
    }
}
