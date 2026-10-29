<?php

declare(strict_types=1);

use App\Modules\Customers\Actions\SaveAddress;
use App\Modules\Customers\Contracts\AddressBook;
use App\Modules\Customers\Contracts\Geography;
use App\Modules\Customers\Database\Seeders\GeographySeeder;
use App\Modules\Customers\DTOs\AddressInput;
use App\Modules\Customers\Exceptions\AddressException;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;

beforeEach(function () {
    $this->withoutMiddleware(EnforceSessionPolicy::class); // device-session rules are covered in AccountAreaTest
    $this->seed([LanguageSeeder::class, GeographySeeder::class]);
    $this->user = User::factory()->create();
    $this->other = User::factory()->create();

    // Dhaka division → Dhaka district → first upazila; and a district that belongs to a different division
    $this->dhaka = (int) DB::table('divisions')->where('name_en', 'Dhaka')->value('id');
    $this->dhakaDistrict = (int) DB::table('districts')->where('division_id', $this->dhaka)->where('name_en', 'Dhaka')->value('id');
    $this->upazila = (int) DB::table('upazilas')->where('district_id', $this->dhakaDistrict)->orderBy('id')->value('id');
    $this->otherDistrict = (int) DB::table('districts')->where('division_id', '!=', $this->dhaka)->orderBy('id')->value('id');

    $this->form = fn (array $over = []) => array_merge([
        'recipient' => 'Rahim Uddin', 'phone' => '01712345678', 'division_id' => $this->dhaka, 'district_id' => $this->dhakaDistrict, 'upazila_id' => $this->upazila,
        'area' => 'Mirpur 10', 'address_line' => 'House 5, Road 3', 'landmark' => 'Near the mosque',
    ], $over);
});

describe('geography data', function () {
    it('holds the full country with names in both languages and a real hierarchy', function () {
        expect(DB::table('divisions')->count())->toBe(8)->and(DB::table('districts')->count())->toBe(64)->and(DB::table('upazilas')->count())->toBe(494);
        expect(DB::table('districts')->whereNotIn('division_id', DB::table('divisions')->select('id'))->count())->toBe(0)
            ->and(DB::table('upazilas')->whereNotIn('district_id', DB::table('districts')->select('id'))->count())->toBe(0);

        foreach (['divisions', 'districts', 'upazilas'] as $table) {
            expect(DB::table($table)->where('name_en', '')->orWhere('name_bn', '')->count())->toBe(0, $table)
                ->and(DB::table($table)->whereRaw("name_bn NOT REGEXP '[ঀ-৿]'")->count())->toBe(0, "{$table}: every Bangla name contains Bangla script");
        }
    });

    it('can be seeded again without duplicating anything', function () {
        $this->seed(GeographySeeder::class);

        expect(DB::table('upazilas')->count())->toBe(494);
    });

    it('answers in the visitor\'s language and checks a chain of ids', function () {
        $geo = app(Geography::class);

        expect(collect($geo->divisions())->pluck('name'))->toContain('Dhaka');
        app()->setLocale('bn');
        expect(collect($geo->divisions())->pluck('name'))->toContain('ঢাকা');

        expect($geo->resolve($this->dhaka, $this->dhakaDistrict, $this->upazila))->toHaveKeys(['division', 'district', 'upazila'])
            ->and($geo->resolve($this->dhaka, $this->otherDistrict, $this->upazila))->toBeNull()   // district from another division
            ->and($geo->resolve($this->dhaka, $this->dhakaDistrict, 99999))->toBeNull();
    });
});

describe('address book', function () {
    it('saves an address, cleans the phone number and makes the first address the default', function () {
        $this->actingAs($this->user, 'web')->post('/en/account/addresses', ($this->form)(['phone' => '০১৭১২৩৪৫৬৭৮', 'postal_code' => '১২১৬']))->assertRedirect('/en/account/addresses');

        $a = CustomerAddress::firstOrFail();
        expect($a->phone)->toBe('+8801712345678')->and($a->postal_code)->toBe('1216')->and($a->is_default)->toBeTrue()->and($a->recipient)->toBe('Rahim Uddin');
        $this->actingAs($this->user, 'web')->get('/en/account/addresses')->assertOk()->assertSee('Rahim Uddin')->assertSee('01712345678')->assertSee('Default')->assertSee('Mirpur 10');
    });

    it('keeps exactly one default and promotes another when the default is deleted', function () {
        foreach (['A', 'B', 'C'] as $n) {
            $this->actingAs($this->user, 'web')->post('/en/account/addresses', ($this->form)(['recipient' => "Person {$n}", 'label' => $n]));
        }
        $rows = CustomerAddress::orderBy('id')->get();
        expect($rows->where('is_default', true))->toHaveCount(1)->and($rows[0]->is_default)->toBeTrue();

        $this->actingAs($this->user, 'web')->post("/en/account/addresses/{$rows[2]->public_id}/default")->assertRedirect();
        expect(CustomerAddress::where('is_default', true)->pluck('label')->all())->toBe(['C']);

        $this->actingAs($this->user, 'web')->post('/en/account/addresses', ($this->form)(['label' => 'D', 'make_default' => '1']));
        expect(CustomerAddress::where('is_default', true)->pluck('label')->all())->toBe(['D']);

        $this->actingAs($this->user, 'web')->delete('/en/account/addresses/'.CustomerAddress::where('label', 'D')->value('public_id'))->assertRedirect();
        expect(CustomerAddress::where('is_default', true)->count())->toBe(1)->and(CustomerAddress::count())->toBe(3);
    });

    it('lets the database itself refuse a second default', function () {
        $this->actingAs($this->user, 'web')->post('/en/account/addresses', ($this->form)());
        $this->actingAs($this->user, 'web')->post('/en/account/addresses', ($this->form)());
        $second = CustomerAddress::orderByDesc('id')->firstOrFail();

        expect(fn () => $second->forceFill(['is_default' => true])->save())->toThrow(QueryException::class);
    });

    it('edits an address', function () {
        $this->actingAs($this->user, 'web')->post('/en/account/addresses', ($this->form)());
        $a = CustomerAddress::firstOrFail();

        $this->actingAs($this->user, 'web')->get("/en/account/addresses/{$a->public_id}/edit")->assertOk()->assertSee('Rahim Uddin');
        $this->actingAs($this->user, 'web')->put("/en/account/addresses/{$a->public_id}", ($this->form)(['recipient' => 'Karim', 'address_line' => 'Flat 2B']))->assertRedirect('/en/account/addresses');

        expect($a->fresh()->recipient)->toBe('Karim')->and($a->fresh()->address_line)->toBe('Flat 2B')->and(CustomerAddress::count())->toBe(1);
    });

    it('stops at ten addresses', function () {
        foreach (range(1, 10) as $_) {
            $this->actingAs($this->user, 'web')->post('/en/account/addresses', ($this->form)())->assertRedirect();
        }

        $this->actingAs($this->user, 'web')->post('/en/account/addresses', ($this->form)())->assertSessionHasErrors('address_line');
        expect(CustomerAddress::count())->toBe(10);
    });

    it('explains mistakes in plain words, in the right language', function () {
        $this->actingAs($this->user, 'web')->post('/en/account/addresses', ($this->form)(['phone' => '12345']))->assertSessionHasErrors(['phone' => 'Enter a valid Bangladesh mobile number, like 01712345678.']);
        $this->actingAs($this->user, 'web')->post('/bn/account/addresses', ($this->form)(['phone' => '12345']))->assertSessionHasErrors(['phone' => 'সঠিক বাংলাদেশি মোবাইল নম্বর দিন, যেমন ০১৭১২৩৪৫৬৭৮।']);
        $this->actingAs($this->user, 'web')->post('/en/account/addresses', ($this->form)(['district_id' => $this->otherDistrict]))->assertSessionHasErrors('upazila_id');
        $this->actingAs($this->user, 'web')->post('/en/account/addresses', ($this->form)(['postal_code' => '12']))->assertSessionHasErrors('postal_code');
        $this->actingAs($this->user, 'web')->post('/en/account/addresses', ($this->form)(['recipient' => '', 'address_line' => '']))->assertSessionHasErrors(['recipient', 'address_line']);
        $this->actingAs($this->user, 'web')->post('/en/account/addresses', ($this->form)(['upazila_id' => 999999]))->assertSessionHasErrors('upazila_id');

        expect(CustomerAddress::count())->toBe(0);
    });

    it('works without JavaScript: every district and upazila is on the page under its parent', function () {
        $html = $this->actingAs($this->user, 'web')->get('/en/account/addresses/create')->assertOk()->getContent();

        expect(substr_count((string) $html, '<optgroup'))->toBe(8 + 64)->and($html)->toContain('<optgroup label="Dhaka"');
        $this->actingAs($this->user, 'web')->get('/bn/account/addresses/create')->assertOk()->assertSee('<optgroup label="ঢাকা"', false)->assertSee('উপজেলা / থানা');
    });

    it('keeps the form after a mistake', function () {
        $this->actingAs($this->user, 'web')->from('/en/account/addresses/create')->post('/en/account/addresses', ($this->form)(['phone' => 'bad']))->assertRedirect('/en/account/addresses/create');
        $html = $this->actingAs($this->user, 'web')->withSession(['_old_input' => ($this->form)(['phone' => 'bad']), 'errors' => new ViewErrorBag])->get('/en/account/addresses/create')->getContent();

        expect($html)->toContain('Rahim Uddin');
    });
});

describe('who can see what', function () {
    it('keeps one customer out of another customer\'s addresses', function () {
        $this->actingAs($this->user, 'web')->post('/en/account/addresses', ($this->form)());
        $mine = CustomerAddress::firstOrFail();

        $this->actingAs($this->other, 'web')->get("/en/account/addresses/{$mine->public_id}/edit")->assertNotFound();
        $this->actingAs($this->other, 'web')->put("/en/account/addresses/{$mine->public_id}", ($this->form)(['recipient' => 'Hacker']))->assertNotFound();
        $this->actingAs($this->other, 'web')->delete("/en/account/addresses/{$mine->public_id}")->assertNotFound();
        $this->actingAs($this->other, 'web')->post("/en/account/addresses/{$mine->public_id}/default")->assertNotFound();
        $this->actingAs($this->other, 'web')->get('/en/account/addresses')->assertOk()->assertDontSee('Rahim Uddin');

        expect($mine->fresh()->recipient)->toBe('Rahim Uddin')->and(app(AddressBook::class)->find((int) $this->other->getAuthIdentifier(), $mine->public_id))->toBeNull();
    });

    it('sends visitors who are not signed in to the sign-in page', function () {
        $this->get('/en/account/addresses')->assertRedirect();
        $this->post('/en/account/addresses', ($this->form)())->assertRedirect();
    });

    it('gives each customer an independent default', function () {
        $this->actingAs($this->user, 'web')->post('/en/account/addresses', ($this->form)());
        $this->actingAs($this->other, 'web')->post('/en/account/addresses', ($this->form)());

        expect(CustomerAddress::where('is_default', true)->count())->toBe(2);
    });
});

it('answers the address pages in a bounded number of queries', function () {
    foreach (range(1, 8) as $_) {
        $this->actingAs($this->user, 'web')->post('/en/account/addresses', ($this->form)());
    }

    DB::enableQueryLog();
    $this->actingAs($this->user, 'web')->get('/en/account/addresses')->assertOk();
    $list = count(DB::getQueryLog());
    DB::flushQueryLog();
    $this->actingAs($this->user, 'web')->get('/en/account/addresses/create')->assertOk();
    $form = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($list)->toBeLessThan(25)->and($form)->toBeLessThan(20);
});

it('has a SaveAddress action that refuses a mismatched chain even when called directly', function () {
    $customerId = (int) DB::table('customers')->insertGetId(['public_id' => strtolower((string) Str::ulid()), 'created_at' => now(), 'updated_at' => now()]);

    expect(fn () => app(SaveAddress::class)->handle($customerId, null, new AddressInput('X', '01712345678', $this->dhaka, $this->otherDistrict, $this->upazila, 'line')))
        ->toThrow(AddressException::class);
});
