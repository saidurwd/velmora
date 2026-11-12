<?php

declare(strict_types=1);

use App\Modules\Customers\Contracts\CustomerProfiles;
use App\Modules\IdentityAccess\Actions\IssueApiSession;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;

/* Phase 11, slice 6a: the customer's own profile. */

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    $this->seed(LanguageSeeder::class);
    $this->amina = User::factory()->verified()->create(['email' => 'amina@example.com', 'name' => 'Amina Rahman']);
    $this->babul = User::factory()->verified()->create(['email' => 'babul@example.com', 'name' => 'Babul Hasan']);
    app(CustomerProfiles::class)->ensureForUser($this->amina->id, 'Amina Rahman', 'amina@example.com', null);
    app(CustomerProfiles::class)->ensureForUser($this->babul->id, 'Babul Hasan', 'babul@example.com', null);
    $this->as = function (User $u) {
        auth()->forgetGuards();

        return ['Authorization' => 'Bearer '.app(IssueApiSession::class)->start($u, 'Pixel', 'android', 'password')->accessToken];
    };
});

it('shows my profile and changes my name and birth date, nobody else’s', function () {
    $show = $this->getJson('/api/v1/me/profile', ($this->as)($this->amina));
    $show->assertOk()->assertJsonPath('data.name', 'Amina Rahman')->assertJsonPath('data.birth_date', null);
    assertMatchesOpenApi($show, 'GET', '/api/v1/me/profile');

    $put = $this->putJson('/api/v1/me/profile', ['name' => '  Amina  Rahman Khan ', 'birth_date' => '১৯৯০-০৫-১২'], ($this->as)($this->amina));
    $put->assertOk()->assertJsonPath('data.birth_date', '1990-05-12');
    assertMatchesOpenApi($put, 'PUT', '/api/v1/me/profile');
    expect($put->json('data.name'))->toContain('Khan')->and(DB::table('customers')->where('user_id', $this->babul->id)->value('name'))->toBe('Babul Hasan');
});

it('refuses a missing name, a future or silly birth date', function () {
    $h = fn () => ($this->as)($this->amina);

    $this->putJson('/api/v1/me/profile', ['name' => ''], $h())->assertStatus(422)->assertJsonValidationErrors(['name']);
    $this->putJson('/api/v1/me/profile', ['name' => 'A', 'birth_date' => now()->addDay()->format('Y-m-d')], $h())->assertStatus(422)->assertJsonValidationErrors(['birth_date']);
    $this->putJson('/api/v1/me/profile', ['name' => 'A', 'birth_date' => '1800-01-01'], $h())->assertStatus(422)->assertJsonValidationErrors(['birth_date']);
    $this->putJson('/api/v1/me/profile', ['name' => 'A', 'birth_date' => 'yesterday'], $h())->assertStatus(422);
});

it('needs a signed-in customer', function () {
    $this->getJson('/api/v1/me/profile')->assertStatus(401);
    $this->putJson('/api/v1/me/profile', ['name' => 'X'])->assertStatus(401);
});
