<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support\Api;

/** A schema reduced to what compatibility cares about (see OpenApiCompatibility): its types, properties, which are always there, its list item, and its allowed values. */
final readonly class SchemaShape
{
    /**
     * @param  list<string>  $types
     * @param  array<string, SchemaShape>  $props
     * @param  list<string>  $required
     * @param  list<mixed>|null  $enum
     */
    public function __construct(public array $types = [], public array $props = [], public array $required = [], public ?SchemaShape $items = null, public ?array $enum = null) {}
}
