<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Models\User;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;

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

/** @param list<string> $permissions */
function catalogStaff(array $permissions): User
{
    $staff = User::factory()->staff()->create(['email' => 'cat'.uniqid().'@example.com']);
    foreach ($permissions as $p) {
        $staff->givePermissionTo($p);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $staff;
}

function enBn(string $en, ?string $bn = null): array
{
    return ['en' => ['name' => $en], 'bn' => ['name' => $bn ?? '']];
}
