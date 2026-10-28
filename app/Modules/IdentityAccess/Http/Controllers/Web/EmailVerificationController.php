<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Controllers\Web;

use App\Modules\IdentityAccess\Actions\VerifyEmail;
use App\Modules\IdentityAccess\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EmailVerificationController
{
    public function notice(Request $request): View|RedirectResponse
    {
        return $request->user()?->email_verified_at !== null ? redirect()->route('account') : view('identity-access::auth.verify-notice');
    }

    /** Signed link from the email. First use verifies the address and signs the person in (fresh registrations only). */
    public function verify(Request $request, string $id, string $hash, VerifyEmail $action): RedirectResponse
    {
        $user = User::query()->where('public_id', $id)->where('account_type', 'customer')->first();

        if ($user === null || ! $action->handle($user, $hash)) {
            return redirect()->route('login')->with('status', __('identity-access::auth.verify.link_used'));
        }

        Auth::guard('web')->login($user);

        return redirect()->route('account')->with('status', __('identity-access::auth.verify.success'));
    }

    public function resend(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user !== null && $user->email !== null && $user->email_verified_at === null) {
            $user->sendEmailVerificationNotification();
        }

        return back()->with('status', __('identity-access::auth.verify.resent'));
    }
}
