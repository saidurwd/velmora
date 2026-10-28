<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Controllers\Web;

use App\Modules\IdentityAccess\Actions\LoginWithPassword;
use App\Modules\IdentityAccess\Actions\RegisterCustomerWithEmail;
use App\Modules\IdentityAccess\Enums\LoginStatus;
use App\Modules\IdentityAccess\Http\Requests\LoginRequest;
use App\Modules\IdentityAccess\Http\Requests\RegisterRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController
{
    public function showLogin(): View
    {
        return view('identity-access::auth.login');
    }

    public function login(LoginRequest $request, LoginWithPassword $login): RedirectResponse
    {
        $outcome = $login->handle((string) $request->validated('identifier'), (string) $request->validated('password'), $request->ip());

        if ($outcome->status === LoginStatus::Throttled) {
            return back()->withInput($request->only('identifier'))->withErrors(['identifier' => __('auth.throttle', ['seconds' => $outcome->retryAfterSeconds])]);
        }

        if (! $outcome->succeeded() || $outcome->user === null) {
            // one message for unknown account, wrong password, locked and disabled
            return back()->withInput($request->only('identifier'))->withErrors(['identifier' => __('auth.failed')]);
        }

        // SessionGuard::login() already rotates the session id (fixation defence); rotating again would orphan the device record
        Auth::guard('web')->login($outcome->user, $request->boolean('remember'));

        return redirect()->intended(route('account'));
    }

    public function showRegister(): View
    {
        return view('identity-access::auth.register');
    }

    public function register(RegisterRequest $request, RegisterCustomerWithEmail $register): RedirectResponse
    {
        $register->handle(
            (string) $request->validated('name'),
            (string) $request->validated('email'),
            (string) $request->validated('password'),
            app()->getLocale(),
        );

        // identical for new and existing addresses
        return redirect()->route('register.sent');
    }

    public function registered(): View
    {
        return view('identity-access::auth.register-sent');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
