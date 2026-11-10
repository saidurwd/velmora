<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Throwable;

/** Connects to a mail server and signs in, then hangs up. Sends no message. Kept apart so tests can replace it. */
class SmtpHandshake
{
    /** @throws Throwable when the server cannot be reached or refuses the sign-in */
    public function check(string $host, int $port, bool $tls, ?string $username, ?string $password): void
    {
        $stream = new SocketStream;
        $stream->setTimeout(10.0);
        $transport = new EsmtpTransport($host, $port, $tls, stream: $stream);
        $username === null || $transport->setUsername($username);
        $password === null || $transport->setPassword($password);

        try {
            $transport->start();
        } finally {
            $transport->stop();
        }
    }
}
