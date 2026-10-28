<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Controllers\Admin;

use App\Modules\IdentityAccess\Actions\RequestPasswordReset;
use App\Modules\IdentityAccess\Actions\ResetPassword;
use App\Modules\IdentityAccess\Http\Requests\ForgotPasswordRequest;
use App\Modules\IdentityAccess\Http\Requests\ResetPasswordRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AdminPasswordController
{
    public function showForgot(): View
    {
        return view('identity-access::admin.forgot-password');
    }

    public function sendLink(ForgotPasswordRequest $request, RequestPasswordReset $action): RedirectResponse
    {
        $action->handle((string) $request->validated('email'), 'staff');

        return back()->with('status', __('identity-access::auth.forgot.sent'));
    }

    public function showReset(Request $request, string $token): View
    {
        return view('identity-access::admin.reset-password', ['token' => $token, 'email' => (string) $request->query('email')]);
    }

    public function reset(ResetPasswordRequest $request, ResetPassword $action): RedirectResponse
    {
        if (! $action->handle((string) $request->validated('email'), (string) $request->validated('token'), (string) $request->validated('password'), 'staff')) {
            return back()->withInput($request->only('email'))->withErrors(['email' => __('identity-access::auth.reset.invalid')]);
        }

        return redirect()->route('admin.login')->with('status', __('identity-access::auth.reset.done'));
    }
}
