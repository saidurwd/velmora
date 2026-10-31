<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

/**
 * Minimal real order rows with the given ids, for tests of things that point at an order (coupon redemptions, payments, ...).
 * `$over` replaces any column (for example `['payment_method_code' => 'online', 'subtotal_minor' => 150000, 'grand_total_minor' => 150000]`).
 *
 * @param  list<int>  $ids
 * @param  array<string, mixed>  $over
 */
function fakeOrders(array $ids, array $over = []): void
{
    foreach ($ids as $id) {
        DB::table('orders')->insertOrIgnore(array_replace([
            'id' => $id, 'public_id' => strtolower((string) Str::ulid()), 'order_number' => sprintf('VM-TEST-%06d', $id),
            'contact_name' => 'Test', 'contact_phone' => '+8801712345678', 'locale' => 'en', 'subtotal_minor' => 0, 'discount_minor' => 0, 'shipping_minor' => 0,
            'cod_fee_minor' => 0, 'tax_minor' => 0, 'grand_total_minor' => 0, 'tax_inclusive' => false, 'payment_method_code' => 'cod',
            'placed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ], $over));
    }
}
