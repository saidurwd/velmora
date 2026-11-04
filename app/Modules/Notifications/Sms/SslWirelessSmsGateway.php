<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Sms;

use App\Modules\Notifications\Contracts\SmsGateway;
use App\Modules\Notifications\DTOs\SmsResult;
use App\Modules\Platform\DTOs\PhoneNumber;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * SSL Wireless ISMS Plus, single send (`POST {base}/send-sms`), exactly as written in docs/vendor/sslwireless/README.md (the provider's own API documentation).
 * The token and the sender id travel in the JSON body; the calling server's IP must be whitelisted in the provider's portal. A message is "accepted" only when the
 * provider says so for this recipient (`status_code` 200 and `sms_status` SUCCESS); that is not a delivery report, which the documentation does not offer.
 *
 * Nothing about the message, the phone number or the token is ever logged or placed in an error: failures carry the provider's numeric code and our own plain label.
 * Nothing is retried here (the caller decides; a retry could send twice). The message is never cut short: one that is too long is refused.
 */
final class SslWirelessSmsGateway implements SmsGateway
{
    private const MAX_MESSAGE = 1000;   // characters, per the documentation

    /** @var array<int, string> the documentation's status codes, in our words */
    private const CODES = [
        4001 => 'unauthorized', 4002 => 'sender_id_not_permitted', 4003 => 'ip_not_whitelisted', 4004 => 'invalid_request', 4005 => 'endpoint_not_found', 4020 => 'invalid_reference',
        4022 => 'parameter_missing', 4023 => 'duplicate_reference', 4024 => 'duplicate_number', 4025 => 'invalid_number', 4026 => 'blocked_number', 4027 => 'message_too_long',
        4028 => 'invalid_message_data', 4029 => 'too_many_requests', 4030 => 'limit_exceeded', 4031 => 'tps_exceeded', 4032 => 'invalid_sms', 4033 => 'too_many_otp_requests', 5000 => 'provider_error',
    ];

    public function __construct(private readonly string $baseUrl, private readonly string $apiToken, private readonly string $sid, private readonly int $timeoutSeconds = 10) {}

    public function send(PhoneNumber $to, string $message, array $meta = []): SmsResult
    {
        if (trim($this->apiToken) === '' || trim($this->sid) === '' || trim($this->baseUrl) === '') {
            return new SmsResult(false, null, 'sslwireless:not_configured');
        }
        if ($message === '' || mb_strlen($message) > self::MAX_MESSAGE) {
            return new SmsResult(false, null, 'sslwireless:message_length');   // never cut short: the customer would get half an OTP
        }

        try {
            $response = Http::timeout($this->timeoutSeconds)->connectTimeout(5)->acceptJson()->asJson()->post(rtrim($this->baseUrl, '/').'/send-sms', [
                'api_token' => $this->apiToken,
                'sid' => $this->sid,
                'msisdn' => ltrim($to->e164(), '+'),   // with the country code, digits only
                'sms' => $message,
                'csms_id' => 'v'.bin2hex(random_bytes(9)),   // our unique reference: 19 letters and digits (the limit is 20)
            ]);
        } catch (ConnectionException) {
            return new SmsResult(false, null, 'sslwireless:connection');
        } catch (Throwable) {
            return new SmsResult(false, null, 'sslwireless:request_failed');
        }

        if (! $response->successful()) {
            return new SmsResult(false, null, 'sslwireless:http_'.$response->status());
        }

        $body = $response->json();
        if (! is_array($body)) {
            return new SmsResult(false, null, 'sslwireless:bad_response');
        }

        $code = (int) ($body['status_code'] ?? 0);
        if (($body['status'] ?? null) !== 'SUCCESS' || $code !== 200) {
            return new SmsResult(false, null, 'sslwireless:'.$code.':'.(self::CODES[$code] ?? 'unknown'));
        }

        $info = $body['smsinfo'][0] ?? null;
        if (! is_array($info) || ($info['sms_status'] ?? null) !== 'SUCCESS') {
            $status = is_array($info) ? strtolower((string) ($info['sms_status'] ?? 'unknown')) : 'missing';

            return new SmsResult(false, null, 'sslwireless:recipient_'.preg_replace('/[^a-z_]/', '', $status));   // INVALID, DUPLICATE, BLOCKED
        }

        $reference = isset($info['reference_id']) ? mb_substr((string) $info['reference_id'], 0, 64) : null;

        return new SmsResult(true, $reference === '' ? null : $reference);
    }
}
