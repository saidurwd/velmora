<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Controllers\Api;

use App\Modules\IdentityAccess\Actions\ChangeEmail;
use App\Modules\IdentityAccess\Actions\ChangePhone;
use App\Modules\IdentityAccess\Actions\ChangePhoneResult;
use App\Modules\IdentityAccess\Enums\OtpIssueStatus;
use App\Modules\IdentityAccess\Http\Requests\Api\ChangeEmailApiRequest;
use App\Modules\IdentityAccess\Http\Requests\Api\OtpRequestRequest;
use App\Modules\IdentityAccess\Http\Requests\Api\OtpVerifyRequest;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Platform\Enums\ErrorCode;
use App\Modules\Platform\Http\ApiProblem;
use Dedoc\Scramble\Attributes\IgnoreResponse;
use Dedoc\Scramble\Attributes\Response as ResponseDoc;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Changing how the shop reaches the person: their email and phone number. Every call needs a fresh confirmation (`POST /auth/reauth`, within 10 minutes).
 * A new number must prove it receives text messages; a new address must prove it receives email.
 */
class ContactController
{
    /**
     * Ask to change the email address
     *
     * A confirmation link goes to the NEW address. The change happens when that link is opened and the person is signed in as the account owner (the link opens the shop's website; sign in there if asked). The answer is the same whether or not the address belongs to someone else.
     */
    #[ResponseDoc(status: 202, type: 'array{data: array{status: string}}')]
    #[IgnoreResponse(200)]
    public function changeEmail(ChangeEmailApiRequest $request, ChangeEmail $change): JsonResponse
    {
        $change->request($this->user($request), (string) $request->validated('email'));

        return response()->json(['data' => ['status' => 'check_your_email']], 202);
    }

    /**
     * Send the verification email again
     *
     * Does nothing (and still answers 202) if the address is already verified.
     */
    #[ResponseDoc(status: 202, type: 'array{data: array{status: string}}')]
    #[IgnoreResponse(200)]
    public function resendVerification(Request $request): JsonResponse
    {
        $user = $this->user($request);

        if ($user->email !== null && $user->email_verified_at === null) {
            $user->sendEmailVerificationNotification();
        }

        return response()->json(['data' => ['status' => 'check_your_email']], 202);
    }

    /**
     * Send a code to a new phone number
     *
     * The code goes to the NEW number. `status` is `sent` or `cooling_down` (a code was sent a moment ago and still works).
     */
    #[ResponseDoc(status: 202, type: 'array{data: array{status: string}}')]
    #[IgnoreResponse(200)]
    public function phoneCode(OtpRequestRequest $request, ChangePhone $change): JsonResponse
    {
        $status = $change->request($request->phone(), $request->ip(), app()->getLocale());

        return match ($status) {
            OtpIssueStatus::Sent => response()->json(['data' => ['status' => 'sent']], 202),
            OtpIssueStatus::CoolingDown => response()->json(['data' => ['status' => 'cooling_down']], 202),
            OtpIssueStatus::TargetLimited, OtpIssueStatus::IpLimited, OtpIssueStatus::DailyCapReached => throw new ApiProblem(ErrorCode::RateLimited),
            OtpIssueStatus::DeliveryFailed => throw new ApiProblem(ErrorCode::OtpUnavailable),
        };
    }

    /**
     * Confirm the new phone number
     *
     * Send the same `phone` and the code. A wrong, expired or used code is `auth.otp_invalid`; a number that belongs to another account is a validation error on `phone`.
     *
     * @status 204
     */
    public function phoneConfirm(OtpVerifyRequest $request, ChangePhone $change): Response
    {
        $result = $change->confirm($this->user($request), $request->phone(), (string) $request->validated('code'));

        return match ($result) {
            ChangePhoneResult::Changed => response()->noContent(),
            ChangePhoneResult::InvalidCode => throw new ApiProblem(ErrorCode::OtpInvalid),
            ChangePhoneResult::AlreadyUsed => throw ValidationException::withMessages(['phone' => [__('identity-access::account.phone.already_used')]]),
        };
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
