<?php

declare(strict_types=1);

namespace App\Modules\Cms\Exceptions;

use DomainException;

/** `reason` is a translation key under cms::policies.errors. */
final class PolicyException extends DomainException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }

    public static function required(): self
    {
        return new self('policies_required');
    }

    /** A policy was released while the person was reading the old one. */
    public static function changed(): self
    {
        return new self('policies_changed');
    }

    public static function kindTaken(): self
    {
        return new self('kind_taken');
    }

    public static function kindLocked(): self
    {
        return new self('kind_locked');
    }
}
