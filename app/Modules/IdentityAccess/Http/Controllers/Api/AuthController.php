<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Controllers\Api;

use App\Modules\Cms\Contracts\PolicyAcceptances;
use App\Modules\IdentityAccess\Actions\ConfirmRecentAuth;
use App\Modules\IdentityAccess\Actions\EndApiSessions;
use App\Modules\IdentityAccess\Actions\IssueApiSession;
use App\Modules\IdentityAccess\Actions\LoginWithPassword;
use App\Modules\IdentityAccess\Actions\RegisterCustomerWithEmail;
use App\Modules\IdentityAccess\Actions\RequestPasswordReset;
use App\Modules\IdentityAccess\Actions\RequestPhoneOtp;
use App\Modules\IdentityAccess\Actions\ResetPassword;
use App\Modules\IdentityAccess\Actions\RotateRefreshToken;
use App\Modules\IdentityAccess\Actions\VerifyPhoneOtpAndSignIn;
use App\Modules\IdentityAccess\Auth\ApiReauth;
use App\Modules\IdentityAccess\Enums\AccountType;
use App\Modules\IdentityAccess\Enums\LoginStatus;
use App\Modules\IdentityAccess\Enums\OtpIssueStatus;
use App\Modules\IdentityAccess\Enums\RefreshStatus;
use App\Modules\IdentityAccess\Http\Requests\Api\ForgotPasswordRequest;
use App\Modules\IdentityAccess\Http\Requests\Api\LoginRequest;
use App\Modules\IdentityAccess\Http\Requests\Api\OtpRequestRequest;
use App\Modules\IdentityAccess\Http\Requests\Api\OtpVerifyRequest;
use App\Modules\IdentityAccess\Http\Requests\Api\ReauthRequest;
use App\Modules\IdentityAccess\Http\Requests\Api\RefreshRequest;
use App\Modules\IdentityAccess\Http\Requests\Api\RegisterRequest;
use App\Modules\IdentityAccess\Http\Requests\Api\ResetPasswordRequest;
use App\Modules\IdentityAccess\Http\Resources\TokenPairResource;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\IdentityAccess\Services\DatabaseApiSessions;
use App\Modules\Platform\Enums\ErrorCode;
use App\Modules\Platform\Http\ApiProblem;
use Dedoc\Scramble\Attributes\IgnoreResponse;
use Dedoc\Scramble\Attributes\Response as ResponseDoc;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/** Signing in from the app. Every route is a thin shell around the Action the website uses; nothing about who may sign in is decided here. */
class AuthController
{
    /**
     * Policies to accept at sign-up
     *
     * The policies a new customer must accept, with the release number to send back as `policy_releases` when registering or when a phone code creates the account. Empty when nothing has been published.
     *
     * @unauthenticated
     *
     * @response array{data: list<array{kind: string, release_id: int, version: int, title: string, url: string|null, effective_at: string}>}
     */
    public function signupPolicies(PolicyAcceptances $policies): JsonResponse
    {
        return response()->json(['data' => array_map(fn ($n): array => [
            'kind' => $n->kind, 'release_id' => $n->releaseId, 'version' => $n->versionNumber, 'title' => $n->title, 'url' => $n->url, 'effective_at' => $n->effectiveAt->toIso8601String(),
        ], $policies->outstanding(null, 'signup', null, app()->getLocale()))]);
    }

    /**
     * Register with email
     *
     * Makes a customer account and emails a verification link. Never returns a token: the person signs in afterwards. The answer is the same whether or not the address already has an account.
     *
     * @unauthenticated
     */
    #[ResponseDoc(status: 202, type: 'array{data: array{status: string}}')]
    #[IgnoreResponse(200)]
    public function register(RegisterRequest $request, RegisterCustomerWithEmail $register): JsonResponse
    {
        try {
            $register->handle((string) $request->validated('name'), (string) $request->validated('email'), (string) $request->validated('password'), app()->getLocale(), $request->policyReleases());
        } catch (DomainException $e) { // a policy changed while the person was reading it (the message is a cms::policies.errors key)
            throw ValidationException::withMessages(['policy_releases' => [__('cms::policies.errors.'.$e->getMessage())]]);
        }

        return response()->json(['data' => ['status' => 'check_your_email']], 202);
    }

    /**
     * Sign in with email or phone and password
     *
     * Customers only. An unknown account, a wrong password, a locked or disabled account and a staff account all get the same `auth.invalid_credentials`. Too many tries get 429 with Retry-After.
     *
     * @unauthenticated
     *
     * @response array{data: array{token_type: string, access_token: string, expires_in: int, refresh_token: string, refresh_expires_in: int, session_id: string, user: array{id: string, name: string|null, email: string|null, phone: string|null, locale: string, email_verified: bool, phone_verified: bool, has_password: bool}}}
     */
    public function login(LoginRequest $request, LoginWithPassword $login, IssueApiSession $issue): JsonResponse
    {
        $outcome = $login->handle((string) $request->validated('identifier'), (string) $request->validated('password'), $request->ip(), AccountType::Customer);

        if ($outcome->status === LoginStatus::Throttled) {
            throw new ApiProblem(ErrorCode::RateLimited, headers: ['Retry-After' => (string) $outcome->retryAfterSeconds]);
        }
        if (! $outcome->succeeded() || $outcome->user === null) {
            throw new ApiProblem(ErrorCode::InvalidCredentials);
        }

        return response()->json(['data' => TokenPairResource::make($issue->start($outcome->user, (string) $request->validated('device_name', ''), $request->validated('platform'), 'password'))]);
    }

    /**
     * Ask for a sign-in code by text message
     *
     * Always answers 202 for a valid number, whether or not it has an account. `cooling_down` means a code was sent a moment ago and is still valid.
     *
     * @unauthenticated
     */
    #[ResponseDoc(status: 202, type: 'array{data: array{status: string}}')]
    #[IgnoreResponse(200)]
    public function otpRequest(OtpRequestRequest $request, RequestPhoneOtp $otp): JsonResponse
    {
        $status = $otp->handle($request->phone(), $request->ip(), app()->getLocale());

        return match ($status) {
            OtpIssueStatus::Sent => response()->json(['data' => ['status' => 'sent']], 202),
            OtpIssueStatus::CoolingDown => response()->json(['data' => ['status' => 'cooling_down']], 202),
            OtpIssueStatus::TargetLimited, OtpIssueStatus::IpLimited, OtpIssueStatus::DailyCapReached => throw new ApiProblem(ErrorCode::RateLimited),
            OtpIssueStatus::DeliveryFailed => throw new ApiProblem(ErrorCode::OtpUnavailable),
        };
    }

    /**
     * Sign in with a text-message code
     *
     * A new number makes a new customer account, exactly as on the website. A wrong, expired or used code, a locked number and a staff number all get the same `auth.otp_invalid`.
     *
     * @unauthenticated
     *
     * @response array{data: array{token_type: string, access_token: string, expires_in: int, refresh_token: string, refresh_expires_in: int, session_id: string, user: array{id: string, name: string|null, email: string|null, phone: string|null, locale: string, email_verified: bool, phone_verified: bool, has_password: bool}}}
     */
    public function otpVerify(OtpVerifyRequest $request, VerifyPhoneOtpAndSignIn $verify, IssueApiSession $issue): JsonResponse
    {
        $outcome = $verify->handle($request->phone(), (string) $request->validated('code'), app()->getLocale(), $request->policyReleases());

        if (! $outcome->succeeded() || $outcome->user === null) {
            throw new ApiProblem(ErrorCode::OtpInvalid);
        }

        return response()->json(['data' => TokenPairResource::make($issue->start($outcome->user, (string) $request->validated('device_name', ''), $request->validated('platform'), 'phone_otp'))]);
    }

    /**
     * Swap the refresh token for a new pair
     *
     * Each refresh token works once. Using one a second time ends the whole sign-in (`auth.refresh_reused`), because it means a copy was stolen.
     *
     * @unauthenticated
     *
     * @response array{data: array{token_type: string, access_token: string, expires_in: int, refresh_token: string, refresh_expires_in: int, session_id: string, user: array{id: string, name: string|null, email: string|null, phone: string|null, locale: string, email_verified: bool, phone_verified: bool, has_password: bool}}}
     */
    public function refresh(RefreshRequest $request, RotateRefreshToken $rotate): JsonResponse
    {
        $result = $rotate->handle((string) $request->validated('refresh_token'));

        return match ($result->status) {
            RefreshStatus::Renewed => response()->json(['data' => TokenPairResource::make($result->pair ?? throw new ApiProblem(ErrorCode::RefreshInvalid))]),
            RefreshStatus::Reused => throw new ApiProblem(ErrorCode::RefreshReused),
            RefreshStatus::Invalid => throw new ApiProblem(ErrorCode::RefreshInvalid),
        };
    }

    /**
     * Sign out this device
     *
     * @status 204
     */
    public function logout(Request $request, DatabaseApiSessions $sessions, EndApiSessions $end): Response
    {
        $user = $this->user($request);
        $family = $sessions->currentFamily($request);
        if ($family !== null) {
            $end->family($user->id, $family);
        } else {
            PersonalAccessToken::findToken((string) $request->bearerToken())?->delete(); // a token with no sign-in row (should not exist) still dies
        }

        return response()->noContent();
    }

    /**
     * Sign out every device
     *
     * @status 204
     */
    public function logoutAll(Request $request, EndApiSessions $end): Response
    {
        $end->all($this->user($request)->id);

        return response()->noContent();
    }

    /**
     * Ask for a password reset link
     *
     * Always answers 202, whether or not the address has an account.
     *
     * @unauthenticated
     */
    #[ResponseDoc(status: 202, type: 'array{data: array{status: string}}')]
    #[IgnoreResponse(200)]
    public function forgot(ForgotPasswordRequest $request, RequestPasswordReset $reset): JsonResponse
    {
        $reset->handle((string) $request->validated('email'));

        return response()->json(['data' => ['status' => 'check_your_email']], 202);
    }

    /**
     * Set a new password with the emailed token
     *
     * Every signed-in device is signed out. A used, expired or wrong token is a validation error on `token`.
     *
     * @unauthenticated
     *
     * @response array{data: array{status: string}}
     */
    public function reset(ResetPasswordRequest $request, ResetPassword $reset): JsonResponse
    {
        if (! $reset->handle((string) $request->validated('email'), (string) $request->validated('token'), (string) $request->validated('password'))) {
            throw ValidationException::withMessages(['token' => [__('passwords.token')]]);
        }

        return response()->json(['data' => ['status' => 'password_changed']]);
    }

    /**
     * Send a code to confirm it is you
     *
     * For people who have no password. The code goes to their verified phone.
     */
    #[ResponseDoc(status: 202, type: 'array{data: array{status: string}}')]
    #[IgnoreResponse(200)]
    public function reauthCode(Request $request, ConfirmRecentAuth $confirm): JsonResponse
    {
        $confirm->requestOtp($this->user($request), $request->ip(), app()->getLocale());

        return response()->json(['data' => ['status' => 'sent']], 202);
    }

    /**
     * Confirm it is you
     *
     * Send the password, or the code from /auth/reauth/code. For the next few minutes this sign-in may do sensitive things (change the password, later: email, phone, delete the account).
     *
     * @status 204
     */
    public function reauth(ReauthRequest $request, ConfirmRecentAuth $confirm, DatabaseApiSessions $sessions, ApiReauth $reauth): Response
    {
        $user = $this->user($request);
        $family = $sessions->currentFamily($request) ?? throw new ApiProblem(ErrorCode::Unauthenticated);

        if ($request->filled('password')) {
            $ok = $confirm->withPassword($user, (string) $request->validated('password'));
            $ok || throw new ApiProblem(ErrorCode::InvalidCredentials);
        } else {
            $confirm->withOtp($user, (string) $request->validated('code')) || throw new ApiProblem(ErrorCode::OtpInvalid);
        }

        $reauth->mark($family);

        return response()->noContent();
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
