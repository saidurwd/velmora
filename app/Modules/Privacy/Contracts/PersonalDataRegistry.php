<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Contracts;

/** How a module says which parts of the export and of the erasure are its own. Erasers run lowest priority number first; the account itself (priority 100) goes last. */
interface PersonalDataRegistry
{
    /** @param  class-string<PersonalDataExporter>  $class */
    public function addExporter(string $class): void;

    /** @param  class-string<PersonalDataEraser>  $class */
    public function addEraser(string $class, int $priority = 50): void;
}
