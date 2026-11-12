<?php

declare(strict_types=1);

use App\Modules\Customers\Database\Seeders\GeographySeeder;
use App\Modules\IdentityAccess\Actions\IssueApiSession;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;

/* Phase 11, slice 4: the customer's address book for the app. Their own addresses only; somebody else's and a missing one look the same. */

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    $this->seed([LanguageSeeder::class, GeographySeeder::class]);
    $this->dhaka = (int) DB::table('districts')->where('name_en', 'Dhaka')->value('id');
    $this->division = (int) DB::table('districts')->where('id', $this->dhaka)->value('division_id');
    $this->upazila = (int) DB::table('upazilas')->where('district_id', $this->dhaka)->value('id');
    $this->body = fn (array $over = []) => array_replace(['recipient' => 'Rahim Uddin', 'phone' => '01712345678', 'division_id' => $this->division, 'district_id' => $this->dhaka, 'upazila_id' => $this->upazila, 'address_line' => 'House 5, Road 2', 'area' => 'Dhanmondi', 'postal_code' => '1205'], $over);
    $this->amina = User::factory()->verified()->create(['email' => 'amina@example.com']);
    $this->babul = User::factory()->verified()->create(['email' => 'babul@example.com']);
    $this->as = function (User $u) {
        auth()->forgetGuards();

        return ['Authorization' => 'Bearer '.app(IssueApiSession::class)->start($u, 'Pixel', 'android', 'password')->accessToken];
    };
});

it('starts empty, saves an address (the first becomes the default) and lists it, in the asked language', function () {
    $this->getJson('/api/v1/me/addresses', ($this->as)($this->amina))->assertOk()->assertJsonPath('data', []);

    $res = $this->postJson('/api/v1/me/addresses', ($this->body)(['label' => 'Home']), ($this->as)($this->amina));
    $res->assertCreated()->assertJsonPath('data.is_default', true)->assertJsonPath('data.phone', '+8801712345678')->assertJsonPath('data.label', 'Home')->assertJsonPath('data.district.id', $this->dhaka);
    assertMatchesOpenApi($res, 'POST', '/api/v1/me/addresses');
    expect($res->json('data.id'))->toMatch('/^[0-9a-z]{26}$/');

    $list = $this->getJson('/api/v1/me/addresses', ($this->as)($this->amina));
    $list->assertOk()->assertJsonCount(1, 'data');
    assertMatchesOpenApi($list, 'GET', '/api/v1/me/addresses');
    $bn = $this->getJson('/api/v1/me/addresses', ($this->as)($this->amina) + ['Accept-Language' => 'bn']);
    expect($bn->json('data.0.district.name'))->not->toBe($list->json('data.0.district.name'))->and($bn->json('data.0.district.id'))->toBe($this->dhaka);
});

it('keeps one default, changes it on request, and updates and deletes an address', function () {
    $h = fn () => ($this->as)($this->amina);
    $first = $this->postJson('/api/v1/me/addresses', ($this->body)(), $h())->json('data.id');
    $second = $this->postJson('/api/v1/me/addresses', ($this->body)(['recipient' => 'Karim', 'address_line' => 'Flat 2']), $h());
    $second->assertCreated()->assertJsonPath('data.is_default', false);

    $this->postJson("/api/v1/me/addresses/{$second->json('data.id')}/default", [], $h())->assertNoContent();
    $defaults = collect($this->getJson('/api/v1/me/addresses', $h())->json('data'))->where('is_default', true);
    expect($defaults)->toHaveCount(1)->and($defaults->first()['id'])->toBe($second->json('data.id'));

    $put = $this->putJson("/api/v1/me/addresses/{$first}", ($this->body)(['recipient' => 'Rahim Changed']), $h());
    $put->assertOk()->assertJsonPath('data.recipient', 'Rahim Changed');
    assertMatchesOpenApi($put, 'PUT', "/api/v1/me/addresses/{$first}");

    $this->deleteJson("/api/v1/me/addresses/{$first}", [], $h())->assertNoContent();
    $this->getJson('/api/v1/me/addresses', $h())->assertJsonCount(1, 'data');
});

it('refuses a phone, a place or a postcode that does not fit, naming the field', function () {
    $h = fn () => ($this->as)($this->amina);

    $this->postJson('/api/v1/me/addresses', ($this->body)(['phone' => 'banana']), $h())->assertStatus(422)->assertJsonPath('code', 'validation.failed')->assertJsonValidationErrors(['phone']);
    $this->postJson('/api/v1/me/addresses', ($this->body)(['upazila_id' => 999999]), $h())->assertStatus(422)->assertJsonValidationErrors(['upazila_id']);
    $other = (int) DB::table('upazilas')->where('district_id', '!=', $this->dhaka)->value('id');
    $this->postJson('/api/v1/me/addresses', ($this->body)(['upazila_id' => $other]), $h())->assertStatus(422)->assertJsonValidationErrors(['upazila_id']);
    $this->postJson('/api/v1/me/addresses', ['recipient' => 'x'], $h())->assertStatus(422)->assertJsonValidationErrors(['phone', 'address_line']);
    expect(DB::table('customer_addresses')->count())->toBe(0);
});

it('holds at most ten addresses', function () {
    $h = fn () => ($this->as)($this->amina);
    foreach (range(1, 10) as $n) {
        $this->postJson('/api/v1/me/addresses', ($this->body)(['address_line' => "House {$n}"]), $h())->assertCreated();
    }

    $this->postJson('/api/v1/me/addresses', ($this->body)(['address_line' => 'One too many']), $h())->assertStatus(422)->assertJsonPath('code', 'address.limit_reached');
    expect(DB::table('customer_addresses')->count())->toBe(10);
});

it('never shows, changes, deletes or defaults somebody else’s address: all of them are 404, and theirs is untouched', function () {
    $theirs = $this->postJson('/api/v1/me/addresses', ($this->body)(['recipient' => 'Babul Private']), ($this->as)($this->babul))->json('data.id');
    $h = fn () => ($this->as)($this->amina);

    $this->putJson("/api/v1/me/addresses/{$theirs}", ($this->body)(['recipient' => 'Hijacked']), $h())->assertNotFound();
    $this->deleteJson("/api/v1/me/addresses/{$theirs}", [], $h())->assertNotFound();
    $this->postJson("/api/v1/me/addresses/{$theirs}/default", [], $h())->assertNotFound();
    $this->putJson('/api/v1/me/addresses/'.str_repeat('0', 26), ($this->body)(), $h())->assertNotFound();
    expect(json_encode($this->getJson('/api/v1/me/addresses', $h())->json()))->not->toContain('Babul Private');
    expect(DB::table('customer_addresses')->where('recipient', 'Babul Private')->count())->toBe(1);
});

it('needs a signed-in customer', function () {
    $this->getJson('/api/v1/me/addresses')->assertStatus(401);
    $this->postJson('/api/v1/me/addresses', ($this->body)())->assertStatus(401);
    auth()->forgetGuards();
    $staff = User::factory()->staff()->create();
    $this->getJson('/api/v1/me/addresses', ['Authorization' => 'Bearer '.$staff->createToken('x', ['customer'], now()->addHour())->plainTextToken])->assertStatus(401);
});

it('follows the language the app asks for in the request, whatever is saved on the account', function () {
    $this->amina->forceFill(['locale' => 'en'])->save();
    $this->postJson('/api/v1/me/addresses', ($this->body)(), ($this->as)($this->amina))->assertCreated();

    $bn = $this->getJson('/api/v1/me/addresses', ($this->as)($this->amina) + ['Accept-Language' => 'bn']);
    $bn->assertHeader('Content-Language', 'bn');

    $this->amina->forceFill(['locale' => 'bn'])->save();
    $this->getJson('/api/v1/me/addresses', ($this->as)($this->amina) + ['Accept-Language' => 'en'])->assertHeader('Content-Language', 'en');
    $this->getJson('/api/v1/me/addresses', ($this->as)($this->amina))->assertHeader('Content-Language', 'en'); // no header: the shop's default (the language is chosen before the account is known)
});
