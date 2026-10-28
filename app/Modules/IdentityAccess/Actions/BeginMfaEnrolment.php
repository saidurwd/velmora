<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Actions;

use App\Modules\IdentityAccess\Mfa\TotpService;
use App\Modules\IdentityAccess\Models\StaffMfaCredential;
use App\Modules\IdentityAccess\Models\User;

/** Creates (or replaces an unconfirmed) TOTP secret for a staff member. A confirmed credential is never replaced here. */
final class BeginMfaEnrolment
{
    public function __construct(private readonly TotpService $totp) {}

    /** @return array{secret: string, otpauth: string, qr_svg: string} */
    public function handle(User $staff): array
    {
        $credential = StaffMfaCredential::query()->where('user_id', $staff->id)->first();

        if ($credential?->isConfirmed()) {
            throw new \DomainException('MFA is already enrolled; reset it through an administrator.');
        }

        $secret = $credential->secret ?? $this->totp->generateSecret();

        if ($credential === null) {
            $credential = new StaffMfaCredential;
            $credential->forceFill(['user_id' => $staff->id, 'secret' => $secret])->save();
        }

        $otpauth = $this->totp->otpauthUrl((string) config('app.name'), (string) $staff->email, $secret);

        return ['secret' => $secret, 'otpauth' => $otpauth, 'qr_svg' => $this->totp->qrSvg($otpauth)];
    }
}
