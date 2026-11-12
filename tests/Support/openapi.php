<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/*
 * API contract helpers (Phase 11). The committed document docs/api/openapi.json is the contract the mobile developers build from;
 * every API response a test makes can be checked against it with assertMatchesOpenApi().
 */

/** @return array<string, mixed> */
function openApiDocument(): array
{
    static $doc = null;

    return $doc ??= json_decode((string) file_get_contents(base_path('docs/api/openapi.json')), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * Checks that a response is one the committed document promises: the operation exists for this route and method, the status is a documented one,
 * and the JSON body satisfies that response's schema.
 */
function assertMatchesOpenApi(TestResponse $response, string $method, string $uri): void
{
    $route = Route::getRoutes()->match(Request::create($uri, $method));
    $template = '/'.ltrim(substr($route->uri(), strlen('api/v1')), '/');
    $doc = openApiDocument();
    $operation = $doc['paths'][$template][strtolower($method)] ?? null;
    expect($operation)->not->toBeNull("{$method} {$template} is not in docs/api/openapi.json");

    $status = (string) $response->getStatusCode();
    $declared = $operation['responses'][$status] ?? $operation['responses']['default'] ?? null;
    expect($declared)->not->toBeNull("{$method} {$template} answered {$status}, which the document does not declare");

    $schema = $declared['content']['application/json']['schema'] ?? null;
    if ($schema === null) {
        return;
    }

    $validator = new Validator;
    $validator->resolver()?->registerRaw((string) file_get_contents(base_path('docs/api/openapi.json')), 'urn:velmora:openapi');
    $pointer = '#/paths/'.rawurlencode(str_replace(['~', '/'], ['~0', '~1'], $template)).'/'.strtolower($method).'/responses/'.$status.'/content/application~1json/schema';
    $result = $validator->validate(json_decode((string) $response->getContent()), (object) ['$ref' => 'urn:velmora:openapi'.$pointer]);

    expect($result->isValid())->toBeTrue($result->isValid() ? '' : "{$method} {$template} {$status}: ".json_encode((new ErrorFormatter)->format($result->error()), JSON_UNESCAPED_SLASHES));
}

/** @return list<array{0: string, 1: string, 2: array<string, mixed>}> method, path, operation */
function openApiOperations(): array
{
    $out = [];
    foreach (openApiDocument()['paths'] as $path => $methods) {
        foreach ($methods as $method => $operation) {
            $out[] = [strtoupper($method), $path, $operation];
        }
    }

    return $out;
}
