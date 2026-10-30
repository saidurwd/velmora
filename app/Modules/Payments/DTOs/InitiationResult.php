<?php

declare(strict_types=1);

namespace App\Modules\Payments\DTOs;

final readonly class InitiationResult
{
    /** @param  array<string, scalar|null>  $summary  what the gateway answered, with secrets removed */
    public function __construct(public string $redirectUrl, public ?string $sessionKey = null, public array $summary = []) {}
}
