<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\Contracts\TemplateRegistry;
use App\Modules\Notifications\DTOs\TemplateDefinition;

/** The messages modules have said they send. Kept in memory for the request; the words themselves live in the database (versions). */
final class TemplateCatalog implements TemplateRegistry
{
    /** @var array<string, TemplateDefinition> */
    private array $definitions = [];

    public function register(TemplateDefinition $definition): void
    {
        $this->definitions[$definition->code] = $definition;
    }

    public function get(string $code): ?TemplateDefinition
    {
        return $this->definitions[$code] ?? null;
    }

    /** @return array<string, TemplateDefinition> by code */
    public function all(): array
    {
        ksort($this->definitions);

        return $this->definitions;
    }
}
