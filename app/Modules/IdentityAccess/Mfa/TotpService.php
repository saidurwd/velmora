<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Mfa;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use PragmaRX\Google2FA\Google2FA;

/** RFC 6238 TOTP (30 s steps, 6 digits, SHA-1 as authenticator apps expect) with replay protection. */
final class TotpService
{
    private const WINDOW = 1; // accept one step either side for clock drift

    public function __construct(private readonly Google2FA $google2fa = new Google2FA) {}

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    /**
     * @param  int|null  $lastUsedStep  the step number accepted last time (a code can only move forward)
     * @return int|null the accepted step on success (store it), null when invalid or replayed
     */
    public function verify(string $secret, string $code, ?int $lastUsedStep): ?int
    {
        $code = (string) preg_replace('/\D/', '', $code);

        if (strlen($code) !== 6) {
            return null;
        }

        // With a null previous step the library returns `true` instead of the step number; 0 makes it return the step.
        $step = $this->google2fa->verifyKeyNewer($secret, $code, $lastUsedStep ?? 0, self::WINDOW);

        return is_int($step) ? $step : null;
    }

    public function otpauthUrl(string $issuer, string $account, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl($issuer, $account, $secret);
    }

    /** Inline SVG QR (no third-party QR service ever sees the secret). */
    public function qrSvg(string $otpauthUrl): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd));

        return $writer->writeString($otpauthUrl);
    }
}
