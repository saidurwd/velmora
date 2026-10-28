<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Sms;

use App\Modules\Notifications\Contracts\SmsGateway;
use App\Modules\Notifications\DTOs\SmsResult;
use App\Modules\Platform\DTOs\PhoneNumber;

/** Test double: records messages in memory. Never touches a network. */
final class FakeSmsGateway implements SmsGateway
{
    /** @var list<array{to: string, message: string, meta: array<string, scalar|null>}> */
    private array $sent = [];

    public bool $fail = false;

    public function send(PhoneNumber $to, string $message, array $meta = []): SmsResult
    {
        if ($this->fail) {
            return new SmsResult(false, error: 'fake failure');
        }

        $this->sent[] = ['to' => $to->e164(), 'message' => $message, 'meta' => $meta];

        return new SmsResult(true, 'fake-'.count($this->sent));
    }

    /** @return list<array{to: string, message: string, meta: array<string, scalar|null>}> */
    public function sent(): array
    {
        return $this->sent;
    }

    public function lastCode(): ?string
    {
        $last = end($this->sent);

        return $last && preg_match('/\b(\d{6})\b/', $last['message'], $m) ? $m[1] : null;
    }
}
