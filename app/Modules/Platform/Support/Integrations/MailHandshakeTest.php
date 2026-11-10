<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support\Integrations;

use App\Modules\Platform\Contracts\IntegrationTest;
use App\Modules\Platform\DTOs\TestOutcome;
use App\Modules\Platform\Support\HostGuard;
use App\Modules\Platform\Support\IntegrationCatalogue;
use App\Modules\Platform\Support\IntegrationCredentials;
use App\Modules\Platform\Support\SmtpHandshake;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Connects to the mail server with the saved details and signs in. Nothing is sent. The server's own reply is never shown (it can name accounts). */
final class MailHandshakeTest implements IntegrationTest
{
    public function __construct(private readonly IntegrationCredentials $credentials, private readonly SmtpHandshake $handshake) {}

    public function provider(): string
    {
        return 'mail';
    }

    public function run(): TestOutcome
    {
        $host = $this->credentials->get('mail', 'host');
        $port = (int) $this->credentials->get('mail', 'port');
        if ($host === null || $port === 0) {
            return new TestOutcome('not_set', false);
        }
        if (! in_array($port, IntegrationCatalogue::MAIL_PORTS, true) || ! HostGuard::reachable($host)) {
            return new TestOutcome('host_blocked', false);
        }

        try {
            $this->handshake->check($host, $port, $this->credentials->get('mail', 'scheme') === 'smtps', $this->credentials->get('mail', 'username'), $this->credentials->get('mail', 'password'));
        } catch (Throwable $e) {
            Log::warning('integrations.mail_test_failed', ['kind' => $e::class]);   // the kind of failure only: the text may hold the server's banner or the account name

            return new TestOutcome('failed', false);
        }

        return new TestOutcome('ok', true);
    }
}
