<?php

declare(strict_types=1);

namespace App\Modules\Notifications\DTOs;

/** How the provider answered for ONE phone. `tokenInvalid`: the phone is gone, forget it. `retryable`: try the message again later (a limit, an outage); otherwise a refusal is final. */
final readonly class PushResult
{
    public function __construct(public bool $accepted, public ?string $providerReference = null, public ?string $error = null, public bool $tokenInvalid = false, public bool $retryable = false) {}
}
