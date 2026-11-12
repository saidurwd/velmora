<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support\Api;

/**
 * Makes a Postman collection (v2.1) and its environment from the API document, so a mobile developer can try every call without typing one. Postman opens it directly,
 * and Bruno imports Postman collections. It is made from the document and checked against it in CI, so it cannot drift: change the API, run `php artisan api:collection`.
 *
 * What it sets up for the developer: a base URL and language in the environment; the access token sent as a bearer token on every call that needs one; the sign-in calls
 * save the tokens they return; `Idempotency-Key` is filled in on placing an order; and the guest cart / order tokens are optional headers (switched off until needed).
 */
final class PostmanCollection
{
    /** @var array<string, mixed> */
    private array $doc = [];

    /**
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    public function collection(array $doc): array
    {
        $this->doc = $doc;
        $folders = [];
        foreach ((array) ($doc['paths'] ?? []) as $path => $methods) {
            foreach ((array) $methods as $method => $operation) {
                $tag = (string) (((array) ($operation['tags'] ?? []))[0] ?? 'Other');
                $folders[$tag][] = $this->item((string) $path, strtoupper((string) $method), (array) $operation);
            }
        }
        ksort($folders);

        return [
            'info' => [
                'name' => (string) ($doc['info']['title'] ?? 'API').' app API v'.(string) ($doc['info']['version'] ?? ''),
                'description' => (string) ($doc['info']['description'] ?? '').' Made from docs/api/openapi.json by `php artisan api:collection`; do not edit by hand. Pick the "Velmora (local)" environment, run Auth > Log in, and the access token is saved for you. Placing an order needs a fresh Idempotency-Key per order (filled in); resend the same key only to retry the same order.',
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
            ],
            'auth' => ['type' => 'bearer', 'bearer' => [['key' => 'token', 'value' => '{{access_token}}', 'type' => 'string']]],
            'item' => array_map(fn (string $name, array $items): array => ['name' => $name, 'item' => $items], array_keys($folders), array_values($folders)),
        ];
    }

    /** @return array<string, mixed> */
    public function environment(): array
    {
        $variable = fn (string $key, string $value, string $type = 'default'): array => ['key' => $key, 'value' => $value, 'type' => $type, 'enabled' => true];

        return [
            'name' => 'Velmora (local)',
            'values' => [
                $variable('base_url', 'http://localhost:8000/api/v1'), $variable('locale', 'en'), $variable('platform', 'android'), $variable('app_version', '1.0.0'),
                $variable('access_token', '', 'secret'), $variable('refresh_token', '', 'secret'), $variable('cart_token', '', 'secret'), $variable('order_token', '', 'secret'),
            ],
            '_postman_variable_scope' => 'environment',
        ];
    }

    /**
     * @param  array<string, mixed>  $op
     * @return array<string, mixed>
     */
    private function item(string $path, string $method, array $op): array
    {
        $public = isset($op['security']) && array_filter((array) $op['security']) === [];
        $query = [];
        $variables = [];
        foreach ((array) ($op['parameters'] ?? []) as $p) {
            $p = (array) $p;
            if (($p['in'] ?? '') === 'query') {
                $query[] = ['key' => (string) $p['name'], 'value' => $this->scalar($this->sample((array) ($p['schema'] ?? []))), 'disabled' => ($p['required'] ?? false) !== true];
            } elseif (($p['in'] ?? '') === 'path') {
                $variables[] = ['key' => (string) $p['name'], 'value' => '', 'description' => 'from an earlier answer'];
            }
        }

        $headers = [['key' => 'Accept', 'value' => 'application/json'], ['key' => 'Accept-Language', 'value' => '{{locale}}'], ['key' => 'X-App-Platform', 'value' => '{{platform}}'], ['key' => 'X-App-Version', 'value' => '{{app_version}}']];
        if (str_starts_with($path, '/cart') || str_starts_with($path, '/checkout') || str_contains($path, '/hold')) {
            $headers[] = ['key' => 'X-Cart-Token', 'value' => '{{cart_token}}', 'disabled' => true, 'description' => 'a guest\'s cart; leave off when signed in'];
        }
        if (str_starts_with($path, '/orders/')) {
            $headers[] = ['key' => 'X-Order-Token', 'value' => '{{order_token}}', 'disabled' => true, 'description' => 'a guest\'s proof for this one order; leave off when signed in'];
        }
        if ($path === '/checkout/orders') {
            $headers[] = ['key' => 'Idempotency-Key', 'value' => '{{$guid}}', 'description' => 'a new key for each order; resend the SAME key only to retry the same order'];
        }

        $request = [
            'method' => $method,
            'header' => $headers,
            'url' => [
                'raw' => '{{base_url}}/'.implode('/', array_map($this->colon(...), $this->parts($path))),
                'host' => ['{{base_url}}'],
                'path' => array_map($this->colon(...), $this->parts($path)),
                'query' => $query,
                'variable' => $variables,
            ],
            'description' => trim((string) ($op['description'] ?? '')),
        ];
        if ($public) {
            $request['auth'] = ['type' => 'noauth'];
            if ($this->maySignIn($path)) {   // a guest or a signed-in customer: sending a wrong token is refused, so the header is off until the developer has signed in
                $request['header'][] = ['key' => 'Authorization', 'value' => 'Bearer {{access_token}}', 'disabled' => true, 'description' => 'switch on when signed in'];
            }
        }
        $body = $op['requestBody']['content']['application/json']['schema'] ?? null;
        if ($body !== null) {
            $request['header'][] = ['key' => 'Content-Type', 'value' => 'application/json'];
            $request['body'] = ['mode' => 'raw', 'raw' => (string) json_encode($this->sample((array) $body), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'options' => ['raw' => ['language' => 'json']]];
        }

        $item = ['name' => (string) ($op['summary'] ?? $method.' '.$path), 'request' => $request];
        if (in_array($op['operationId'] ?? '', ['v1.auth.login', 'v1.auth.otp.verify', 'v1.auth.refresh'], true)) {
            $item['event'] = [['listen' => 'test', 'script' => ['type' => 'text/javascript', 'exec' => [
                'const d = pm.response.json().data;',
                'if (d && d.access_token) { pm.environment.set("access_token", d.access_token); pm.environment.set("refresh_token", d.refresh_token); }',
                '// the refresh token works ONCE: always store the new one that comes back, and never send an old one again.',
            ]]]];
        }
        if (($op['operationId'] ?? '') === 'v1.cart.items.add') {
            $item['event'] = [['listen' => 'test', 'script' => ['type' => 'text/javascript', 'exec' => ['const t = pm.response.headers.get("X-Cart-Token"); if (t) { pm.environment.set("cart_token", t); }   // a guest\'s cart: send it back on every cart and checkout call']]]];
        }
        if (($op['operationId'] ?? '') === 'v1.checkout.orders') {
            $item['event'] = [['listen' => 'test', 'script' => ['type' => 'text/javascript', 'exec' => ['const d = (pm.response.json() || {}).data; if (d && d.order_token) { pm.environment.set("order_token", d.order_token); }']]]];
        }

        return $item;
    }

    /** @return list<string> */
    private function parts(string $path): array
    {
        return array_values(array_filter(explode('/', $path), fn (string $part): bool => $part !== ''));
    }

    /** A path segment `{id}` as Postman writes a path variable: `:id`. */
    private function colon(string $part): string
    {
        return str_starts_with($part, '{') && str_ends_with($part, '}') ? ':'.substr($part, 1, -1) : $part;
    }

    /** The calls a guest and a signed-in customer both make (the cart, checkout, one's own order, holding a time). */
    private function maySignIn(string $path): bool
    {
        return $path !== '/cart/merge' && (str_starts_with($path, '/cart') || str_starts_with($path, '/checkout') || str_starts_with($path, '/orders/{id}') || str_ends_with($path, '/hold'));
    }

    private function scalar(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : '';
    }

    /**
     * An example value for a schema: its own example or default, else the first allowed value, else something of the right type.
     *
     * @param  array<string, mixed>  $schema
     */
    private function sample(array $schema, int $depth = 0): mixed
    {
        if ($depth > 6) {
            return null;
        }
        if (isset($schema['$ref'])) {
            $node = $this->doc;
            foreach (explode('/', ltrim((string) $schema['$ref'], '#/')) as $part) {
                $node = $node[$part] ?? [];
            }

            return $this->sample((array) $node, $depth + 1);
        }
        foreach (['example', 'default'] as $key) {
            if (isset($schema[$key])) {
                return $schema[$key];
            }
        }
        if (isset($schema['enum'])) {
            return array_values(array_filter((array) $schema['enum'], fn ($v) => $v !== null))[0] ?? null;
        }
        foreach (['allOf', 'anyOf', 'oneOf'] as $union) {
            foreach ((array) ($schema[$union] ?? []) as $branch) {
                $s = (array) $branch;
                if (($s['type'] ?? null) !== 'null') {
                    return $this->sample($s, $depth + 1);
                }
            }
        }

        $type = array_values(array_filter((array) ($schema['type'] ?? 'string'), fn ($t) => $t !== 'null'))[0] ?? 'string';

        return match ($type) {
            'integer' => max(1, (int) ($schema['minimum'] ?? 1)),
            'number' => 1.0,
            'boolean' => true,
            'array' => isset($schema['items']) ? [$this->sample((array) $schema['items'], $depth + 1)] : [],
            'object' => $this->object($schema, $depth),
            default => (string) ($schema['format'] ?? '') === 'email' ? 'name@example.com' : 'string',
        };
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function object(array $schema, int $depth): mixed
    {
        $props = [];
        foreach ((array) ($schema['properties'] ?? []) as $name => $p) {
            $props[(string) $name] = $this->sample((array) $p, $depth + 1);
        }

        return $props === [] ? new \stdClass : $props;
    }
}
