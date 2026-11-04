<?php

declare(strict_types=1);

namespace Tests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests(); // no test may reach a real service (payment, SMS, courier): outgoing HTTP must be faked or the test fails

        // Opt-in time-bomb check: `TEST_NOW=2027-03-01 vendor/bin/pest --testsuite=Feature` runs the suite as if it were that day, so a test that only passes because of
        // today's date shows up long before that date comes. (Tests that fix their own clock are not affected. Not for the Database suite: its separate processes keep real time.)
        if (($testNow = getenv('TEST_NOW')) !== false && $testNow !== '') {
            $this->travelTo(CarbonImmutable::parse($testNow, 'UTC'));
        }

        $this->withoutVite(); // views are tested without needing a built manifest; CI also runs `npm run build`

        // The suite wipes its database. Refuse to run against anything that is not a *_test schema.
        $database = (string) config('database.connections.'.config('database.default').'.database');

        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException("Refusing to run tests against non-test database [{$database}].");
        }
    }
}
