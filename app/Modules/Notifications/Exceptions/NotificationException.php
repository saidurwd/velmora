<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Exceptions;

use DomainException;

/** A refusal with a reason key that has a translation under `notifications::errors`. */
final class NotificationException extends DomainException
{
    /** @param  array<string, scalar>  $params */
    public function __construct(public readonly string $reason, public readonly array $params = [])
    {
        parent::__construct($reason);
    }
}
