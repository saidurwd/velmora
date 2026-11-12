<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Contracts;

use App\Modules\Notifications\DTOs\PushMessage;
use App\Modules\Notifications\DTOs\PushResult;

/** Provider-independent push transport for one phone. Adapters: Log (dev), Fake (tests), FCM HTTP v1 (docs/vendor/fcm/). */
interface PushGateway
{
    public function send(string $pushToken, PushMessage $message): PushResult;
}
