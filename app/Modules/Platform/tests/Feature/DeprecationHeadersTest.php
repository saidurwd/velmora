<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/* Phase 11, slice 8: a call that is going away says so, and is otherwise unchanged. */

beforeEach(function () {
    Route::middleware('deprecated:2027-06-30')->get('/__test/old', fn () => response()->json(['ok' => true]));
    Route::middleware('deprecated:2027-06-30,/api/v1/new')->get('/__test/older', fn () => response()->json(['ok' => true]));
    Route::get('/__test/current', fn () => response()->json(['ok' => true]));
});

it('adds Deprecation and Sunset to a deprecated call and changes nothing else', function () {
    $res = $this->getJson('/__test/old')->assertOk()->assertExactJson(['ok' => true]);

    expect($res->headers->get('Deprecation'))->toBe('true')->and($res->headers->get('Sunset'))->toBe('Wed, 30 Jun 2027 00:00:00 GMT')->and($res->headers->has('Link'))->toBeFalse();
});

it('names the replacement when there is one', function () {
    expect($this->getJson('/__test/older')->assertOk()->headers->get('Link'))->toBe('</api/v1/new>; rel="successor-version"');
});

it('says nothing on a call that is not deprecated', function () {
    $res = $this->getJson('/__test/current')->assertOk();

    expect($res->headers->has('Deprecation'))->toBeFalse()->and($res->headers->has('Sunset'))->toBeFalse();
});
