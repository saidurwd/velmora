<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Push;

use App\Modules\Notifications\Contracts\PushGateway;
use App\Modules\Notifications\DTOs\PushMessage;
use App\Modules\Notifications\DTOs\PushResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Firebase Cloud Messaging, HTTP v1 (`POST https://fcm.googleapis.com/v1/projects/{project}/messages:send`), exactly as saved in docs/vendor/fcm/README.md.
 * The server proves itself with a short-lived access token made from the service account (a signed assertion exchanged at Google's token endpoint) and reuses it
 * until shortly before it ends. The service account file is read from the path in the server's setting; it is never stored in the database or logged.
 *
 * The answer for ONE phone: accepted · gone (HTTP 404 / UNREGISTERED: forget this phone) · retryable (429, 500, 503, 401, a timeout, no token) · refused for good (anything else).
 * Nothing about the message, the push token or the credentials is placed in an error: failures carry the provider's status text and our own plain label.
 * Nothing is retried here (the caller decides; a retry could show the message twice).
 */
final class FcmPushGateway implements PushGateway
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const MAX_BYTES = 4096;   // the provider's limit for the whole message

    public function __construct(private readonly string $serviceAccountFile, private readonly ?string $projectId = null, private readonly int $timeoutSeconds = 10, private readonly string $sendBase = 'https://fcm.googleapis.com') {}

    public function send(string $pushToken, PushMessage $message): PushResult
    {
        $account = $this->account();
        if ($account === null) {
            return new PushResult(false, error: 'fcm:not_configured', retryable: true);
        }
        $project = ($this->projectId !== null && $this->projectId !== '') ? $this->projectId : (string) $account['project_id'];

        $body = ['message' => [
            'token' => $pushToken,
            'notification' => ['title' => $message->title, 'body' => $message->body],
            'data' => $message->data === [] ? new \stdClass : $message->data,
            'android' => ['priority' => 'HIGH', 'ttl' => '86400s'],
            'apns' => ['headers' => ['apns-priority' => '10']],
        ]];
        if (strlen((string) json_encode($body, JSON_UNESCAPED_UNICODE)) > self::MAX_BYTES) {
            return new PushResult(false, error: 'fcm:message_too_big');
        }

        $accessToken = $this->accessToken($account);
        if ($accessToken === null) {
            return new PushResult(false, error: 'fcm:no_access_token', retryable: true);
        }

        try {
            $response = Http::withToken($accessToken)->acceptJson()->asJson()->timeout($this->timeoutSeconds)->post(rtrim($this->sendBase, '/')."/v1/projects/{$project}/messages:send", $body);
        } catch (Throwable $e) {
            return new PushResult(false, error: 'fcm:'.class_basename($e), retryable: true);
        }

        if ($response->successful() && is_string($response->json('name'))) {
            return new PushResult(true, mb_substr((string) $response->json('name'), 0, 64));
        }

        $status = (int) $response->status();
        $code = $this->errorCode($response->json());
        if ($status === 401) {
            Cache::forget($this->cacheKey($account));   // the stored access token was not accepted: make a new one on the next try
        }

        return new PushResult(false, error: 'fcm:'.$status.':'.$code, tokenInvalid: $status === 404 || $code === 'UNREGISTERED', retryable: in_array($status, [401, 429, 500, 503], true));
    }

    /** @return array{client_email: string, private_key: string, private_key_id: string, project_id: string}|null null when the file is not set, not readable or not a service account */
    private function account(): ?array
    {
        if ($this->serviceAccountFile === '' || ! is_readable($this->serviceAccountFile)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($this->serviceAccountFile), true);
        if (! is_array($data)) {
            return null;
        }
        foreach (['client_email', 'private_key', 'private_key_id', 'project_id'] as $field) {
            if (! isset($data[$field]) || ! is_string($data[$field]) || $data[$field] === '') {
                return null;
            }
        }

        return ['client_email' => $data['client_email'], 'private_key' => $data['private_key'], 'private_key_id' => $data['private_key_id'], 'project_id' => $data['project_id']];
    }

    /** @param  array{client_email: string, private_key: string, private_key_id: string, project_id: string}  $account */
    private function accessToken(array $account): ?string
    {
        $cached = Cache::get($this->cacheKey($account));
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $now = time();
        $segment = fn (array $part): string => rtrim(strtr(base64_encode((string) json_encode($part, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
        $unsigned = $segment(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $account['private_key_id']]).'.'.$segment(['iss' => $account['client_email'], 'scope' => self::SCOPE, 'aud' => self::TOKEN_URL, 'iat' => $now, 'exp' => $now + 3600]);
        $signature = '';
        if (! @openssl_sign($unsigned, $signature, $account['private_key'], OPENSSL_ALGO_SHA256)) {
            return null;
        }

        try {
            $response = Http::asForm()->acceptJson()->timeout($this->timeoutSeconds)->post(self::TOKEN_URL, ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $unsigned.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=')]);
        } catch (Throwable) {
            return null;
        }
        $token = $response->successful() ? $response->json('access_token') : null;
        if (! is_string($token) || $token === '') {
            return null;
        }

        Cache::put($this->cacheKey($account), $token, max(60, (int) $response->json('expires_in', 3600) - 120));   // reused until two minutes before it ends

        return $token;
    }

    /** @param  array{client_email: string, private_key: string, private_key_id: string, project_id: string}  $account */
    private function cacheKey(array $account): string
    {
        return 'fcm.access_token.'.sha1($account['client_email'].'|'.$account['private_key_id']);
    }

    private function errorCode(mixed $json): string
    {
        if (! is_array($json)) {
            return 'unknown';
        }
        foreach ((array) ($json['error']['details'] ?? []) as $detail) {
            if (is_array($detail) && is_string($detail['errorCode'] ?? null)) {
                return mb_substr($detail['errorCode'], 0, 40);
            }
        }

        return is_string($json['error']['status'] ?? null) ? mb_substr($json['error']['status'], 0, 40) : 'unknown';
    }
}
