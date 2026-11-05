<?php

declare(strict_types=1);

namespace App\Modules\Notifications\DTOs;

/**
 * One message the shop can send. `variables` are the only names its words may use; `defaults` are the first words (by language, `[subject, body]`; subject is
 * only for email); `sample` is what the preview fills in.
 */
final readonly class TemplateDefinition
{
    /**
     * @param  list<string>  $variables
     * @param  array<string, array{0: string|null, 1: string}>  $defaults
     * @param  array<string, string>  $sample
     * @param  list<string>  $multiline  variables (a subset of `variables`) whose values may hold line breaks, such as a list of items; every other value is one line
     */
    public function __construct(public string $code, public string $channel, public string $kind, public array $variables, public array $defaults, public array $sample = [], public array $multiline = []) {}
}
