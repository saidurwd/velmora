<?php

declare(strict_types=1);

namespace App\Modules\Platform\Exceptions;

use DomainException;

/** `reason` is a translation key under platform::ops.errors. */
final class OpsException extends DomainException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }

    /** Somebody else already retried or forgot it, or it never existed. */
    public static function gone(): self
    {
        return new self('job_gone');
    }

    public static function notRetryable(): self
    {
        return new self('job_not_retryable');
    }

    public static function unknownCheck(): self
    {
        return new self('check_unknown');
    }
}
