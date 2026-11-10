<?php

declare(strict_types=1);

namespace App\Modules\Platform\DTOs;

/** What a connection test found: a short code (the key of its translated sentence under platform::integrations.tests) and whether it is good news. Never any credential. */
final readonly class TestOutcome
{
    public function __construct(public string $code, public bool $ok) {}
}
