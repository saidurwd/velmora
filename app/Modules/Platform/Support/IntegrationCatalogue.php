<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use InvalidArgumentException;

/**
 * The provider credentials staff may keep in the admin area, and where each falls back to on the server. Only CREDENTIALS are here: the switches that decide
 * whether a provider is live (sandbox, PAYMENTS_ALLOW_LIVE, MAIL_MAILER, SMS_DRIVER) stay in the server environment and are never editable from a page.
 */
final class IntegrationCatalogue
{
    public const SCOPE = 'integration';

    public const PROVIDERS = ['sslcommerz', 'sms', 'mail'];

    /** SMTP ports a staff-chosen mail server may use (the usual mail ports; no other service is reachable through the test). */
    public const MAIL_PORTS = [25, 465, 587, 2525];

    /**
     * Field => secret?, the server setting it falls back to, the kind of value and its limit.
     *
     * @return array<string, array{secret: bool, config: string, type: string, max: int, choices?: list<string>}>
     */
    public static function fields(string $provider): array
    {
        return match ($provider) {
            'sslcommerz' => [
                'store_id' => ['secret' => false, 'config' => 'payments.sslcommerz.store_id', 'type' => 'store_id', 'max' => 60],
                'store_password' => ['secret' => true, 'config' => 'payments.sslcommerz.store_password', 'type' => 'password', 'max' => 100],
            ],
            'sms' => [
                'api_token' => ['secret' => true, 'config' => 'services.sms.sslwireless.api_token', 'type' => 'sms_token', 'max' => 50],
                'sid' => ['secret' => false, 'config' => 'services.sms.sslwireless.sid', 'type' => 'sms_sid', 'max' => 20],
            ],
            'mail' => [
                'host' => ['secret' => false, 'config' => 'mail.mailers.smtp.host', 'type' => 'host', 'max' => 190],
                'port' => ['secret' => false, 'config' => 'mail.mailers.smtp.port', 'type' => 'port', 'max' => 5],
                'scheme' => ['secret' => false, 'config' => 'mail.mailers.smtp.scheme', 'type' => 'choice', 'max' => 5, 'choices' => ['smtp', 'smtps']],
                'username' => ['secret' => false, 'config' => 'mail.mailers.smtp.username', 'type' => 'text', 'max' => 190],
                'password' => ['secret' => true, 'config' => 'mail.mailers.smtp.password', 'type' => 'password', 'max' => 190],
                'from_address' => ['secret' => false, 'config' => 'mail.from.address', 'type' => 'email', 'max' => 190],
                'from_name' => ['secret' => false, 'config' => 'mail.from.name', 'type' => 'text', 'max' => 120],
            ],
            default => throw new InvalidArgumentException("Unknown integration [{$provider}]."),
        };
    }

    public static function settingKey(string $provider, string $field): string
    {
        isset(self::fields($provider)[$field]) || throw new InvalidArgumentException("Unknown field [{$field}] of [{$provider}].");

        return "{$provider}.{$field}";
    }

    /** @return list<string> */
    public static function keys(string $provider): array
    {
        return array_map(fn (string $f) => self::settingKey($provider, $f), array_keys(self::fields($provider)));
    }
}
