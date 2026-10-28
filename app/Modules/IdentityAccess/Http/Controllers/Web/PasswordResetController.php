<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Controllers\Web;

use App\Modules\IdentityAccess\Actions\RequestPasswordReset;
use App\Modules\IdentityAccess\Actions\ResetPassword;
use App\Modules\IdentityAccess\Http\Requests\ForgotPasswordRequest;
use App\Modules\IdentityAccess\Http\Requests\ResetPasswordRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PasswordResetController
{
    public function showForgot(): View
    {
        return view('identity-access::auth.forgot-password');
    }

    public function sendLink(ForgotPasswordRequest $request, RequestPasswordReset $action): RedirectResponse
    {
        $action->handle((string) $request->validated('email'));

        // same answer whether or not the address exists
        return back()->with('status', __('identity-access::auth.forgot.sent'));
    }

    public function showReset(Request $request, string $token): View
    {
        return view('identity-access::auth.reset-password', ['token' => $token, 'email' => (string) $request->query('email')]);
    }

    public function reset(ResetPasswordRequest $request, ResetPassword $action): RedirectResponse
    {
        $ok = $action->handle((string) $request->validated('email'), (string) $request->validated('token'), (string) $request->validated('password'));

        if (! $ok) {
            return back()->withInput($request->only('email'))->withErrors(['email' => __('identity-access::auth.reset.invalid')]);
        }

        return redirect()->route('login')->with('status', __('identity-access::auth.reset.done'));
    }
}
