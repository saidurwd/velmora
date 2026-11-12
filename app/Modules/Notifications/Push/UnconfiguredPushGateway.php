<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Push;

use App\Modules\Notifications\Contracts\PushGateway;
use App\Modules\Notifications\DTOs\PushMessage;
use App\Modules\Notifications\DTOs\PushResult;
use RuntimeException;

/** Bound when PUSH_DRIVER names something that does not exist: fail loudly (staff see the message as failing), never silently drop it. */
final class UnconfiguredPushGateway implements PushGateway
{
    public function __construct(private readonly string $driver) {}

    public function send(string $pushToken, PushMessage $message): PushResult
    {
        throw new RuntimeException("Push driver [{$this->driver}] has no adapter. Use log, fake or fcm.");
    }
}
