<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests(); // no test may reach a real service (payment, SMS, courier): outgoing HTTP must be faked or the test fails

        $this->withoutVite(); // views are tested without needing a built manifest; CI also runs `npm run build`

        // The suite wipes its database. Refuse to run against anything that is not a *_test schema.
        $database = (string) config('database.connections.'.config('database.default').'.database');

        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException("Refusing to run tests against non-test database [{$database}].");
        }
    }
}
