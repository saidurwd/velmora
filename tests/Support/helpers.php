<?php

declare(strict_types=1);

use Illuminate\Testing\TestResponse;

// Shared test helpers (loaded from tests/Pest.php).

const GOOD_PASSWORD = 'correct-horse-battery';

/** The test client does not keep cookies between requests; carry the session cookie like a browser would. */
function carrySession(object $test, TestResponse $response): void
{
    $cookies = [];
    foreach ($response->headers->getCookies() as $cookie) {
        $cookies[$cookie->getName()] = $cookie->getValue();
    }
    $test->withUnencryptedCookies($cookies);
}
