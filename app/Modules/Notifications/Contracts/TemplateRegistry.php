<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Contracts;

use App\Modules\Notifications\DTOs\TemplateDefinition;

/** Where a module says which messages it sends, what each is for and which variables its words may use. Staff change the words; they cannot add variables. */
interface TemplateRegistry
{
    public function register(TemplateDefinition $definition): void;
}
