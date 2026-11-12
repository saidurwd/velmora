<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support\Api;

/**
 * Tells what a change to the API document would break for an app that already ships. It compares two OpenAPI documents (the frozen baseline and the current one)
 * and lists every change an existing app could trip on. Adding things is fine; removing or narrowing is not.
 *
 * Breaking, in an answer (what the app reads): a path or method gone; a success status gone; a property gone; a property that was always there and now may be missing;
 * a property's type widened (it could be null now, or a string where it was a number); a route that needed no sign-in and now does.
 * Breaking, in a request (what the app sends): a new required property or parameter; a property whose type was narrowed or whose allowed values were cut;
 * a body that was optional and is now required.
 * NOT breaking (apps must tolerate it, and the guide says so): new paths, methods, optional request properties, response properties, success statuses, and new values
 * in a response's list of allowed values.
 */
final class OpenApiCompatibility
{
    private const METHODS = ['get', 'put', 'post', 'delete', 'patch'];

    /** @var array<string, mixed> */
    private array $oldDoc = [];

    /** @var array<string, mixed> */
    private array $newDoc = [];

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return list<string> one plain sentence per breaking change; empty when an app built on `$old` still works with `$new`
     */
    public function breaking(array $old, array $new): array
    {
        $this->oldDoc = $old;
        $this->newDoc = $new;
        $out = [];

        foreach ((array) ($old['paths'] ?? []) as $path => $operations) {
            foreach (self::METHODS as $method) {
                if (! isset($operations[$method])) {
                    continue;
                }
                $label = strtoupper($method).' '.$path;
                $now = $new['paths'][$path][$method] ?? null;
                if ($now === null) {
                    $out[] = "{$label} was removed";

                    continue;
                }
                $this->operation($label, $operations[$method], $now, $out);
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  list<string>  $out
     */
    private function operation(string $label, array $old, array $new, array &$out): void
    {
        if ($this->needsSignIn($old) === false && $this->needsSignIn($new) === true) {
            $out[] = "{$label} now needs a sign-in";
        }

        $this->parameters($label, (array) ($old['parameters'] ?? []), (array) ($new['parameters'] ?? []), $out);

        $oldBody = $old['requestBody'] ?? null;
        $newBody = $new['requestBody'] ?? null;
        if ($newBody !== null) {
            if (($newBody['required'] ?? false) === true && ($oldBody === null || ($oldBody['required'] ?? false) !== true)) {
                $out[] = "{$label} now needs a request body";
            }
            if ($oldBody !== null) {
                $this->compare($label.' request', $this->shape($oldBody['content']['application/json']['schema'] ?? [], $this->oldDoc), $this->shape($newBody['content']['application/json']['schema'] ?? [], $this->newDoc), true, $out);
            }
        }

        foreach ((array) ($old['responses'] ?? []) as $status => $response) {
            if (! str_starts_with((string) $status, '2')) {
                continue;
            }
            if (! isset($new['responses'][$status])) {
                $out[] = "{$label} no longer answers {$status}";

                continue;
            }
            $before = $response['content']['application/json']['schema'] ?? null;
            $after = $new['responses'][$status]['content']['application/json']['schema'] ?? null;
            if ($before !== null) {
                $after === null
                    ? $out[] = "{$label} {$status} no longer says what it returns"
                    : $this->compare("{$label} {$status}", $this->shape($before, $this->oldDoc), $this->shape($after, $this->newDoc), false, $out);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function needsSignIn(array $operation): bool
    {
        // an operation with its own empty security list is public; one without inherits the document's bearer scheme
        return ! (isset($operation['security']) && array_filter((array) $operation['security']) === []);
    }

    /**
     * @param  array<mixed>  $old
     * @param  array<mixed>  $new
     * @param  list<string>  $out
     */
    private function parameters(string $label, array $old, array $new, array &$out): void
    {
        $key = fn (array $p): string => ($p['in'] ?? '').':'.strtolower((string) ($p['name'] ?? ''));
        $before = [];
        foreach ($old as $p) {
            $before[$key((array) $p)] = (array) $p;
        }
        foreach ($new as $raw) {
            $p = (array) $raw;
            $was = $before[$key($p)] ?? null;
            if (($p['required'] ?? false) === true && ($was === null || ($was['required'] ?? false) !== true)) {
                $out[] = "{$label} now needs the {$p['in']} parameter {$p['name']}";
            }
            if ($was !== null && isset($was['schema'], $p['schema'])) {
                $this->compare("{$label} parameter {$p['name']}", $this->shape((array) $was['schema'], $this->oldDoc), $this->shape((array) $p['schema'], $this->newDoc), true, $out);
            }
        }
    }

    /**
     * @param  list<string>  $out
     */
    private function compare(string $at, SchemaShape $old, SchemaShape $new, bool $request, array &$out): void
    {
        if ($old->types !== [] && $new->types !== []) {
            // an answer must not start to be a type it was not; a request must not stop accepting a type it did
            [$from, $to] = $request ? [$old->types, $new->types] : [$new->types, $old->types];
            $unmatched = array_filter($from, fn (string $t): bool => ! in_array($t, $to, true) && ! ($t === 'integer' && in_array('number', $to, true)));
            if ($unmatched !== []) {
                $out[] = "{$at}: the type changed from ".implode('|', $old->types).' to '.implode('|', $new->types);
            }
        }

        if ($request && $old->enum !== null && $new->enum !== null && array_diff($old->enum, $new->enum) !== []) {
            $out[] = "{$at}: these values are no longer accepted: ".implode(', ', array_map('strval', array_diff($old->enum, $new->enum)));
        }
        if ($request && $old->enum === null && $new->enum !== null) {
            $out[] = "{$at}: only some values are accepted now";
        }

        if ($request) {
            foreach ($new->required as $name) {
                if (! in_array($name, $old->required, true) && isset($new->props[$name])) {
                    $out[] = "{$at}: {$name} is now required";
                }
            }
        } else {
            foreach ($old->props as $name => $_) {
                if (! array_key_exists($name, $new->props)) {
                    $out[] = "{$at}: {$name} was removed";
                } elseif (in_array($name, $old->required, true) && ! in_array($name, $new->required, true)) {
                    $out[] = "{$at}: {$name} is no longer always there";
                }
            }
        }

        foreach ($old->props as $name => $shape) {
            if (isset($new->props[$name])) {
                $this->compare("{$at}.{$name}", $shape, $new->props[$name], $request, $out);
            }
        }
        if ($old->items !== null && $new->items !== null) {
            $this->compare("{$at}[]", $old->items, $new->items, $request, $out);
        }
    }

    /**
     * A schema reduced to what compatibility cares about, with references followed.
     *
     * @param  array<mixed>  $schema
     * @param  array<mixed>  $doc
     */
    private function shape(array $schema, array $doc, int $depth = 0): SchemaShape
    {
        if ($depth > 12) {
            return new SchemaShape;   // a schema that refers to itself: compared as far as it goes
        }
        if (isset($schema['$ref'])) {
            $node = $doc;
            foreach (explode('/', ltrim((string) $schema['$ref'], '#/')) as $part) {
                $node = $node[str_replace(['~1', '~0'], ['/', '~'], $part)] ?? [];
            }

            return $this->shape((array) $node, $doc, $depth + 1);
        }

        $types = isset($schema['type']) ? array_map('strval', (array) $schema['type']) : [];
        $props = array_map(fn ($p): SchemaShape => $this->shape((array) $p, $doc, $depth + 1), (array) ($schema['properties'] ?? []));
        $required = array_map('strval', (array) ($schema['required'] ?? []));
        $items = isset($schema['items']) ? $this->shape((array) $schema['items'], $doc, $depth + 1) : null;
        $enum = isset($schema['enum']) ? array_values((array) $schema['enum']) : null;
        if (isset($schema['nullable']) && $schema['nullable'] === true && $types !== []) {
            $types[] = 'null';
        }

        foreach (['allOf'] as $merge) {
            foreach ((array) ($schema[$merge] ?? []) as $part) {
                $s = $this->shape((array) $part, $doc, $depth + 1);
                $types = array_merge($types, $s->types);
                $props += $s->props;
                $required = array_merge($required, $s->required);
                $items ??= $s->items;
            }
        }
        foreach (['anyOf', 'oneOf'] as $union) {
            $branches = array_map(fn ($b): SchemaShape => $this->shape((array) $b, $doc, $depth + 1), (array) ($schema[$union] ?? []));
            foreach ($branches as $s) {
                $types = array_merge($types, $s->types);
                $props += $s->props;
                $items ??= $s->items;
            }
            if ($branches !== []) {
                $required = array_merge($required, array_values(array_intersect(...array_map(fn (SchemaShape $s): array => $s->required, $branches))));
            }
        }

        $types = array_values(array_unique($types));
        sort($types);

        return new SchemaShape($types, $props, array_values(array_unique($required)), $items, $enum);
    }
}
