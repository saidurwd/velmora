<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use Illuminate\Support\Facades\RateLimiter;

/* Phase 12: a staff member may change things at most 120 times a minute; reading is never counted; another staff member has their own count. */

beforeEach(function () {
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->staff = catalogStaff(['catalog.update']);
    $this->other = catalogStaff(['catalog.update']);
    RateLimiter::clear('admin-write|'.$this->staff->getAuthIdentifier());
    $this->write = fn ($user) => $this->actingAs($user, 'admin')->post('/admin/search/synonyms', []);   // refused by validation: cheap, and still a write
});

it('refuses the 121st change in a minute with 429 and Retry-After, and counts reads for nothing', function () {
    for ($i = 0; $i < 120; $i++) {
        expect(($this->write)($this->staff)->getStatusCode())->not->toBe(429);
    }
    for ($i = 0; $i < 30; $i++) {
        expect($this->actingAs($this->staff, 'admin')->get('/admin/search/synonyms')->getStatusCode())->not->toBe(429);
    }

    ($this->write)($this->staff)->assertStatus(429)->assertHeader('Retry-After');
    ($this->write)($this->other)->assertStatus(302);   // someone else, same address: not affected (validation sends them back)
});
