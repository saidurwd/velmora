<?php

declare(strict_types=1);

namespace App\Modules\Search\DTOs;

final readonly class Suggestion
{
    public function __construct(public string $name, public string $slug) {}
}
