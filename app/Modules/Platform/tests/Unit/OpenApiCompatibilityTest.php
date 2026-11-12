<?php

declare(strict_types=1);

use App\Modules\Platform\Support\Api\OpenApiCompatibility;

/* Phase 11, slice 8: what counts as breaking an app that is already out there. */

function apiDoc(): array
{
    return [
        'openapi' => '3.1.0',
        'security' => [['http' => []]],
        'paths' => [
            '/things' => [
                'get' => [
                    'parameters' => [['name' => 'page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer']]],
                    'responses' => ['200' => ['content' => ['application/json' => ['schema' => ['type' => 'object', 'required' => ['data'], 'properties' => [
                        'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Thing']],
                        'note' => ['type' => ['string', 'null']],
                    ]]]]], '401' => ['description' => 'x']],
                ],
                'post' => [
                    'security' => [],
                    'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/NewThing']]]],
                    'responses' => ['201' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Thing']]]], '422' => ['description' => 'x']],
                ],
            ],
            '/things/{id}' => ['delete' => ['responses' => ['204' => ['description' => 'gone']]]],
        ],
        'components' => ['schemas' => [
            'Thing' => ['type' => 'object', 'required' => ['id', 'price'], 'properties' => ['id' => ['type' => 'string'], 'price' => ['type' => 'integer'], 'tag' => ['type' => 'string'], 'kind' => ['type' => 'string', 'enum' => ['a', 'b']]]],
            'NewThing' => ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string'], 'size' => ['type' => 'integer'], 'mode' => ['type' => 'string', 'enum' => ['x', 'y']]]],
        ]],
    ];
}

/** Changes the document with a callback and returns what is found. */
function breakingAfter(callable $change): array
{
    $new = apiDoc();
    $change($new);

    return (new OpenApiCompatibility)->breaking(apiDoc(), $new);
}

it('finds nothing when nothing changed', function () {
    expect((new OpenApiCompatibility)->breaking(apiDoc(), apiDoc()))->toBe([]);
});

it('allows everything that only adds', function () {
    expect(breakingAfter(function (&$d) {
        $d['paths']['/other'] = ['get' => ['responses' => ['200' => ['description' => 'new']]]];
        $d['paths']['/things']['put'] = ['responses' => ['200' => ['description' => 'new']]];
        $d['paths']['/things']['get']['responses']['202'] = ['description' => 'new'];
        $d['paths']['/things']['get']['parameters'][] = ['name' => 'sort', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']];
        $d['components']['schemas']['Thing']['properties']['extra'] = ['type' => 'string'];
        $d['components']['schemas']['Thing']['properties']['kind']['enum'][] = 'c';        // a new value in an answer: apps must tolerate it (the guide says so)
        $d['components']['schemas']['NewThing']['properties']['color'] = ['type' => 'string'];
        $d['components']['schemas']['NewThing']['properties']['mode']['enum'][] = 'z';
        $d['components']['schemas']['NewThing']['properties']['size']['type'] = 'number';   // a request that takes more
    }))->toBe([]);
});

it('finds each way an answer can break an app', function (callable $change, string $expected) {
    expect(breakingAfter($change))->toContain($expected);
})->with([
    'a path removed' => [function (&$d) {
        unset($d['paths']['/things/{id}']);
    }, 'DELETE /things/{id} was removed'],
    'a method removed' => [function (&$d) {
        unset($d['paths']['/things']['post']);
    }, 'POST /things was removed'],
    'a success status removed' => [function (&$d) {
        unset($d['paths']['/things']['get']['responses']['200']);
    }, 'GET /things no longer answers 200'],
    'a property removed' => [function (&$d) {
        unset($d['components']['schemas']['Thing']['properties']['tag']);
    }, 'POST /things 201: tag was removed'],
    'a property removed from a list item' => [function (&$d) {
        unset($d['components']['schemas']['Thing']['properties']['price']);
    }, 'GET /things 200.data[]: price was removed'],
    'a property that may now be missing' => [function (&$d) {
        $d['components']['schemas']['Thing']['required'] = ['id'];
    }, 'POST /things 201: price is no longer always there'],
    'a type that may now be null' => [function (&$d) {
        $d['components']['schemas']['Thing']['properties']['price']['type'] = ['integer', 'null'];
    }, 'POST /things 201.price: the type changed from integer to integer|null'],
    'a number that became text' => [function (&$d) {
        $d['components']['schemas']['Thing']['properties']['price']['type'] = 'string';
    }, 'POST /things 201.price: the type changed from integer to string'],
    'a type that was nullable and became something else' => [function (&$d) {
        $d['paths']['/things']['get']['responses']['200']['content']['application/json']['schema']['properties']['note']['type'] = 'integer';
    }, 'GET /things 200.note: the type changed from null|string to integer'],
    'a public route that now needs a sign-in' => [function (&$d) {
        unset($d['paths']['/things']['post']['security']);
    }, 'POST /things now needs a sign-in'],
    'an answer no longer described' => [function (&$d) {
        unset($d['paths']['/things']['post']['responses']['201']['content']);
    }, 'POST /things 201 no longer says what it returns'],
]);

it('finds each way a request can break an app', function (callable $change, string $expected) {
    expect(breakingAfter($change))->toContain($expected);
})->with([
    'a new required property' => [function (&$d) {
        $d['components']['schemas']['NewThing']['required'][] = 'size';
    }, 'POST /things request: size is now required'],
    'a property that takes less' => [function (&$d) {
        $d['components']['schemas']['NewThing']['properties']['size']['type'] = 'string';
    }, 'POST /things request.size: the type changed from integer to string'],
    'allowed values cut' => [function (&$d) {
        $d['components']['schemas']['NewThing']['properties']['mode']['enum'] = ['x'];
    }, 'POST /things request.mode: these values are no longer accepted: y'],
    'a free value that became a short list' => [function (&$d) {
        $d['components']['schemas']['NewThing']['properties']['name']['enum'] = ['only'];
    }, 'POST /things request.name: only some values are accepted now'],
    'a new required parameter' => [function (&$d) {
        $d['paths']['/things']['get']['parameters'][] = ['name' => 'region', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']];
    }, 'GET /things now needs the query parameter region'],
    'an optional parameter that became required' => [function (&$d) {
        $d['paths']['/things']['get']['parameters'][0]['required'] = true;
    }, 'GET /things now needs the query parameter page'],
    'a parameter that takes less' => [function (&$d) {
        $d['paths']['/things']['get']['parameters'][0]['schema']['type'] = 'string';
    }, 'GET /things parameter page: the type changed from integer to string'],
]);

it('follows references, including ones that point at themselves', function () {
    $doc = apiDoc();
    $doc['components']['schemas']['Node'] = ['type' => 'object', 'properties' => ['child' => ['$ref' => '#/components/schemas/Node'], 'name' => ['type' => 'string']]];
    $doc['paths']['/tree'] = ['get' => ['responses' => ['200' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Node']]]]]]];
    $broken = $doc;
    unset($broken['components']['schemas']['Node']['properties']['name']);

    expect((new OpenApiCompatibility)->breaking($doc, $doc))->toBe([])->and((new OpenApiCompatibility)->breaking($doc, $broken))->toContain('GET /tree 200: name was removed');
});

it('sees through a union and a merged schema', function () {
    $old = apiDoc();
    $old['paths']['/mix'] = ['get' => ['responses' => ['200' => ['content' => ['application/json' => ['schema' => ['allOf' => [['$ref' => '#/components/schemas/Thing'], ['type' => 'object', 'properties' => ['extra' => ['anyOf' => [['type' => 'string'], ['type' => 'null']]]]]]]]]]]]];
    $new = $old;
    $new['paths']['/mix']['get']['responses']['200']['content']['application/json']['schema']['allOf'][1]['properties']['extra'] = ['type' => 'integer'];

    expect((new OpenApiCompatibility)->breaking($old, $old))->toBe([])->and((new OpenApiCompatibility)->breaking($old, $new))->toContain('GET /mix 200.extra: the type changed from null|string to integer');
});
