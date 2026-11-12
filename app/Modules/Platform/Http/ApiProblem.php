<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http;

use App\Modules\Platform\Enums\ErrorCode;
use RuntimeException;

/** Thrown by API controllers to answer with a stable problem+json code (rendered by ExceptionRenderer, in the request's language). */
final class ApiProblem extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $extra
     * @param  array<string, string>  $headers
     */
    public function __construct(public readonly ErrorCode $problem, public readonly array $extra = [], public readonly array $headers = [])
    {
        parent::__construct($problem->value);
    }
}
