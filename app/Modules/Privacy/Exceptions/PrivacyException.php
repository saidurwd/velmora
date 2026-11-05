<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Exceptions;

use RuntimeException;

/** A refusal the person can be told about. `reason` is a code that the screens translate; `blockers` lists what stands in the way of an erasure. */
final class PrivacyException extends RuntimeException
{
    /** @param  list<string>  $blockers */
    public function __construct(public readonly string $reason, public readonly array $blockers = [])
    {
        parent::__construct($reason);
    }
}
