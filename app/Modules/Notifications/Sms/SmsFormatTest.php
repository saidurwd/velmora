<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Sms;

use App\Modules\Platform\Contracts\IntegrationTest;
use App\Modules\Platform\DTOs\TestOutcome;
use App\Modules\Platform\Support\IntegrationCredentials;

/** Checks only that the token and sender id look right. It sends nothing and touches no network (a real send would cost money and reach a real phone). */
final class SmsFormatTest implements IntegrationTest
{
    public function __construct(private readonly IntegrationCredentials $credentials) {}

    public function provider(): string
    {
        return 'sms';
    }

    public function run(): TestOutcome
    {
        $token = (string) $this->credentials->get('sms', 'api_token');
        $sid = (string) $this->credentials->get('sms', 'sid');

        if ($token === '' || $sid === '') {
            return new TestOutcome('not_set', false);
        }

        return preg_match('/^[A-Za-z0-9]{1,50}$/', $token) === 1 && preg_match('/^[A-Za-z0-9]{1,20}$/', $sid) === 1
            ? new TestOutcome('format_ok', true)
            : new TestOutcome('format_bad', false);
    }
}
