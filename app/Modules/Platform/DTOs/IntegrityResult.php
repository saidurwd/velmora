<?php

declare(strict_types=1);

namespace App\Modules\Platform\DTOs;

/** What a check found: how many records are wrong (at least this many once the cap is reached) and a few examples staff can look up. */
final readonly class IntegrityResult
{
    /** @param  list<string>  $samples  identifiers staff can look up (public ids where there are any), never names or contact details */
    public function __construct(public int $problems, public array $samples = [], public ?string $note = null) {}

    public static function ok(?string $note = null): self
    {
        return new self(0, [], $note);
    }

    public function isOk(): bool
    {
        return $this->problems === 0;
    }
}
