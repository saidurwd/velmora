<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Push;

use App\Modules\Notifications\Contracts\PushGateway;
use App\Modules\Notifications\DTOs\PushMessage;
use App\Modules\Notifications\DTOs\PushResult;

/** Test double: records messages in memory. Never touches a network. A token listed in `$gone` is answered as no longer registered; `$fail` makes every send fail (retryable). */
final class FakePushGateway implements PushGateway
{
    /** @var list<array{token: string, message: PushMessage}> */
    private array $sent = [];

    /** @var list<string> */
    public array $gone = [];

    /** @var list<string> tokens whose sends fail for now (a temporary refusal) */
    public array $down = [];

    public bool $fail = false;

    public function send(string $pushToken, PushMessage $message): PushResult
    {
        if ($this->fail || in_array($pushToken, $this->down, true)) {
            return new PushResult(false, error: 'fake:unavailable', retryable: true);
        }
        if (in_array($pushToken, $this->gone, true)) {
            return new PushResult(false, error: 'fake:unregistered', tokenInvalid: true);
        }

        $this->sent[] = ['token' => $pushToken, 'message' => $message];

        return new PushResult(true, 'fake-'.count($this->sent));
    }

    /** @return list<array{token: string, message: PushMessage}> */
    public function sent(): array
    {
        return $this->sent;
    }
}
