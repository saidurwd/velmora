<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Controllers\Web;

use App\Modules\IdentityAccess\Actions\RequestPhoneOtp;
use App\Modules\IdentityAccess\Actions\VerifyPhoneOtpAndSignIn;
use App\Modules\IdentityAccess\Enums\OtpIssueStatus;
use App\Modules\IdentityAccess\Http\Requests\PhoneRequest;
use App\Modules\IdentityAccess\Http\Requests\VerifyOtpRequest;
use App\Modules\Platform\DTOs\PhoneNumber;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PhoneAuthController
{
    private const SESSION_KEY = 'otp_phone';

    public function show(): View
    {
        return view('identity-access::auth.phone');
    }

    public function send(PhoneRequest $request, RequestPhoneOtp $action): RedirectResponse
    {
        $phone = $request->phone();
        $status = $action->handle($phone, $request->ip(), app()->getLocale());

        $error = match ($status) {
            OtpIssueStatus::Sent => null,
            OtpIssueStatus::CoolingDown => __('identity-access::auth.otp.cooldown'),
            OtpIssueStatus::TargetLimited, OtpIssueStatus::IpLimited => __('identity-access::auth.otp.limited'),
            OtpIssueStatus::DailyCapReached => __('identity-access::auth.otp.unavailable'),
            OtpIssueStatus::DeliveryFailed => __('identity-access::auth.otp.delivery_failed'),
        };

        if ($status === OtpIssueStatus::CoolingDown) {
            $request->session()->put(self::SESSION_KEY, $phone->e164()); // the earlier code is still valid: let them enter it

            return redirect()->route('login.phone.verify')->withErrors(['code' => $error]);
        }

        if ($error !== null) {
            return back()->withInput()->withErrors(['phone' => $error]);
        }

        $request->session()->put(self::SESSION_KEY, $phone->e164());

        return redirect()->route('login.phone.verify');
    }

    public function showVerify(Request $request): View|RedirectResponse
    {
        $e164 = $request->session()->get(self::SESSION_KEY);
        $phone = is_string($e164) ? PhoneNumber::tryParse($e164) : null;

        if ($phone === null) {
            return redirect()->route('login.phone');
        }

        return view('identity-access::auth.phone-verify', ['phoneMasked' => $phone->masked()]);
    }

    public function verify(VerifyOtpRequest $request, VerifyPhoneOtpAndSignIn $action): RedirectResponse
    {
        $e164 = $request->session()->get(self::SESSION_KEY);
        $phone = is_string($e164) ? PhoneNumber::tryParse($e164) : null;

        if ($phone === null) {
            return redirect()->route('login.phone');
        }

        $outcome = $action->handle($phone, (string) $request->validated('code'), app()->getLocale());

        if (! $outcome->succeeded() || $outcome->user === null) {
            return back()->withErrors(['code' => __('identity-access::auth.otp.invalid')]);
        }

        $request->session()->forget(self::SESSION_KEY);
        Auth::guard('web')->login($outcome->user);

        return redirect()->intended(route('account'));
    }
}
