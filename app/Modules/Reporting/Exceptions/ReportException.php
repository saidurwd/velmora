<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Exceptions;

use RuntimeException;

/** A report or export request that cannot be done. `reason` is a word the screens translate (`reporting::exports.errors.<reason>`). */
final class ReportException extends RuntimeException
{
    /** @param  array<string, scalar>  $params */
    public function __construct(public readonly string $reason, public readonly array $params = [])
    {
        parent::__construct($reason);
    }
}
