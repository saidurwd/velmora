<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Push;

use App\Modules\Notifications\Contracts\PushGateway;
use App\Modules\Notifications\DTOs\PushMessage;
use App\Modules\Notifications\DTOs\PushResult;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/** Development transport: writes the message to the log (never the push token). Refuses to run in production. */
final class LogPushGateway implements PushGateway
{
    public function send(string $pushToken, PushMessage $message): PushResult
    {
        if (app()->environment('production', 'staging')) {
            throw new RuntimeException('LogPushGateway must not be used in production/staging; set PUSH_DRIVER.');
        }
        Log::info('[push:log] '.$message->title.' — '.$message->body, $message->data);

        return new PushResult(true, 'log-'.bin2hex(random_bytes(4)));
    }
}
