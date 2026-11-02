<?php

declare(strict_types=1);

use App\Modules\DigitalProducts\Actions\AllocateLicenseKeys;
use App\Modules\DigitalProducts\Actions\CheckKeyStock;
use App\Modules\DigitalProducts\Actions\ImportLicenseKeys;
use App\Modules\DigitalProducts\Exceptions\DigitalException;
use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Notifications\Sms\FakeSmsGateway;
use App\Modules\Platform\DTOs\Actor;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

beforeEach(function () {
    digitalWorld($this);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    config(['services.sms.driver' => 'fake']);
    $this->owner = User::factory()->create(['email' => 'owner@example.com']);
    $this->stranger = User::factory()->create(['email' => 'stranger@example.com']);
    $this->staff = Actor::system('t');
    $this->manager = catalogStaff(['digital.view', 'digital.manage']);
    $this->viewer = catalogStaff(['digital.view']);
    $this->nobody = catalogStaff(['catalog.view']);

    $this->import = fn (string $sku, array|string $keys) => app(ImportLicenseKeys::class)->handle($this->staff, $sku, is_array($keys) ? implode("\n", $keys) : $keys);
    $this->buy = function (int $qty = 1, ?User $for = null, string $sku = 'BOOK-1', bool $guest = false) {
        $order = ($this->place)([($sku === 'BOOK-1' ? $this->book : $this->book2) => $qty], $guest ? null : ($for ?? $this->owner)->id);
        ($this->pay)($order->id);

        return [$order, DB::table('entitlements')->where('order_id', $order->id)->first()];
    };
    $this->held = fn (int $entitlementId) => DB::table('license_keys')->where('entitlement_id', $entitlementId)->where('status', 'allocated')->count();
    $this->all = fn () => DB::table('license_keys')->orderBy('id')->get();
});

describe('importing keys', function () {
    it('adds the keys encrypted, skips repeats and bad lines, ignores blanks, a header and a byte-order mark, and says what it did', function () {
        $result = ($this->import)('BOOK-1', "\xEF\xBB\xBFkey\nAAAA-1111\n\nBBBB-2222\r\nAAAA-1111\n".str_repeat('x', 201)."\nCCCC-3333\n");

        expect([$result->added, $result->duplicates, $result->invalid])->toBe([3, 1, 1]);
        $again = ($this->import)('BOOK-1', ['BBBB-2222', 'DDDD-4444']);
        expect([$again->added, $again->duplicates])->toBe([1, 1])->and(DB::table('license_keys')->count())->toBe(4)->and(DB::table('license_key_pools')->count())->toBe(1);
    });

    it('never keeps a key in the clear: not in the table, not in the audit trail, and decrypts back only through the app key', function () {
        ($this->import)('BOOK-1', ['SECRET-KEY-ONE', 'SECRET-KEY-TWO']);

        $dump = json_encode(DB::table('license_keys')->get()).json_encode(DB::table('audit_events')->get());
        expect($dump)->not->toContain('SECRET-KEY')->and(DB::table('audit_events')->where('action', 'digital.keys_imported')->count())->toBe(1)
            ->and(Crypt::decryptString(DB::table('license_keys')->orderBy('id')->value('key_encrypted')))->toBe('SECRET-KEY-ONE')
            ->and(DB::table('license_keys')->orderBy('id')->value('fingerprint'))->toBe(ImportLicenseKeys::fingerprint('SECRET-KEY-ONE'))->not->toContain('SECRET');
    });

    it('lets the same key be in two products\' pools, but never twice in one', function () {
        ($this->import)('BOOK-1', ['SHARED-KEY']);
        ($this->import)('BOOK-2', ['SHARED-KEY']);

        expect(DB::table('license_keys')->count())->toBe(2)->and(fn () => DB::table('license_keys')->insert(['pool_id' => DB::table('license_key_pools')->orderBy('id')->value('id'), 'key_encrypted' => 'x', 'fingerprint' => ImportLicenseKeys::fingerprint('SHARED-KEY'), 'status' => 'available', 'created_at' => now()]))->toThrow(QueryException::class);
    });

    it('refuses a product that is not digital, an unknown one, too many lines, a file that is not UTF-8, and one that is too big', function () {
        $refuses = fn (callable $call, string $reason) => expect($call)->toThrow(DigitalException::class, $reason);

        $refuses(fn () => ($this->import)('TEE-1', ['A']), 'variant_not_digital');
        $refuses(fn () => ($this->import)('NOPE', ['A']), 'variant_unknown');
        $refuses(fn () => ($this->import)('BOOK-1', array_map(fn ($i) => 'K-'.$i, range(1, ImportLicenseKeys::MAX_LINES + 1))), 'keys_too_many');
        $refuses(fn () => ($this->import)('BOOK-1', "\xff\xfe\x00bad"), 'keys_not_utf8');
        $refuses(fn () => ($this->import)('BOOK-1', str_repeat('A', ImportLicenseKeys::MAX_BYTES + 1)), 'keys_file_too_large');
        expect(DB::table('license_keys')->count())->toBe(0);
    });

    it('never lets a key, its fingerprint or its pool be changed, or a key be deleted, and a given key stays given', function () {
        ($this->import)('BOOK-1', ['KEY-1']);
        [, $right] = ($this->buy)();
        $key = DB::table('license_keys')->first();

        expect(fn () => DB::table('license_keys')->where('id', $key->id)->update(['key_encrypted' => 'other']))->toThrow(QueryException::class)
            ->and(fn () => DB::table('license_keys')->where('id', $key->id)->delete())->toThrow(QueryException::class)
            ->and($key->status)->toBe('allocated')->and((int) $key->entitlement_id)->toBe($right->id)
            ->and(fn () => DB::table('license_keys')->where('id', $key->id)->update(['entitlement_id' => $right->id + 1]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('license_keys')->where('id', $key->id)->update(['status' => 'available']))->toThrow(QueryException::class);
    });
});

describe('giving keys to customers', function () {
    it('gives one key per unit bought, lowest key first, when the order is paid', function () {
        ($this->import)('BOOK-1', ['K-1', 'K-2', 'K-3', 'K-4']);

        [, $a] = ($this->buy)(2);
        [, $b] = ($this->buy)(1);

        expect((int) $a->keys_needed)->toBe(2)->and(($this->held)($a->id))->toBe(2)->and(($this->held)($b->id))->toBe(1)
            ->and(DB::table('license_keys')->where('entitlement_id', $a->id)->pluck('id')->all())->toBe(DB::table('license_keys')->orderBy('id')->limit(2)->pluck('id')->all())
            ->and(DB::table('license_keys')->where('status', 'available')->count())->toBe(1);
    });

    it('makes no demand for keys when the product has no pool, and still grants the download', function () {
        [, $right] = ($this->buy)(2, null, 'BOOK-2');

        expect((int) $right->keys_needed)->toBe(0)->and(DB::table('license_key_pools')->count())->toBe(0);
    });

    it('gives what there is and lets the rest wait, then fills the oldest order first when more keys arrive', function () {
        ($this->import)('BOOK-1', ['K-1', 'K-2']);
        [, $first] = ($this->buy)(2);
        [, $second] = ($this->buy)(2);
        [, $third] = ($this->buy)(1);

        expect(($this->held)($first->id))->toBe(2)->and(($this->held)($second->id))->toBe(0)->and(($this->held)($third->id))->toBe(0);

        $result = ($this->import)('BOOK-1', ['K-3', 'K-4', 'K-5']);

        expect($result->added)->toBe(3)->and($result->filled)->toBe(3)->and(($this->held)($second->id))->toBe(2)->and(($this->held)($third->id))->toBe(1);   // the second order is served before the third
        expect(app(AllocateLicenseKeys::class)->fillWaiting())->toBe(0)->and(DB::table('license_keys')->where('status', 'available')->count())->toBe(0);
    });

    it('never gives a right more than it is owed, however often it is asked', function () {
        ($this->import)('BOOK-1', ['K-1', 'K-2', 'K-3']);
        [, $right] = ($this->buy)(1);

        foreach (range(1, 3) as $i) {
            expect(app(AllocateLicenseKeys::class)->forEntitlement($right->id))->toBe(0);
        }
        expect(($this->held)($right->id))->toBe(1)->and(DB::table('license_keys')->where('status', 'available')->count())->toBe(2);
    });

    it('gives nothing to a revoked right', function () {
        ($this->import)('BOOK-1', ['K-1']);
        ($this->import)('BOOK-1', []);
        $order = ($this->place)([$this->book => 1], $this->owner->id);
        DB::table('license_key_pools')->first();
        ($this->pay)($order->id);
        $right = DB::table('entitlements')->where('order_id', $order->id)->first();
        DB::table('entitlements')->where('id', $right->id)->update(['status' => 'revoked', 'revoked_at' => now()]);
        ($this->import)('BOOK-1', ['K-2']);

        expect(app(AllocateLicenseKeys::class)->forEntitlement($right->id))->toBe(0)->and(DB::table('license_keys')->where('status', 'available')->count())->toBe(1);
    });
});

describe('what the customer sees', function () {
    it('shows how many keys are ready and says the rest are being prepared, with no key in the page', function () {
        ($this->import)('BOOK-1', ['SECRET-ONE']);
        ($this->buy)(2);

        $html = $this->actingAs($this->owner)->get('/en/account/library')->assertOk()->assertSee('1 of 2 licence keys are ready.')->assertSee('being prepared')->assertSee('Show my keys')->getContent();

        expect($html)->not->toContain('SECRET-ONE');
    });

    it('shows a key-only product with no download button, and the file and the keys together when it has both', function () {
        ($this->import)('BOOK-1', ['K-1']);
        ($this->buy)(1);
        ($this->import)('BOOK-2', ['K-2']);
        ($this->buy)(1, null, 'BOOK-2');
        ($this->upload)('BOOK-2', 'Garden.pdf');

        $html = $this->actingAs($this->owner)->get('/en/account/library')->assertOk()->assertSee('Ready')->getContent();

        expect(substr_count($html, '>Download'))->toBe(1)->and(substr_count($html, 'Show my keys'))->toBe(2);   // only the product with a file has a download button
    });

    it('reveals the keys on a page that is never kept, and records only a count and the key ids', function () {
        ($this->import)('BOOK-1', ['SECRET-ONE', 'SECRET-TWO']);
        [, $right] = ($this->buy)(2);

        $response = $this->actingAs($this->owner)->post("/en/account/library/{$right->public_id}/keys")->assertOk()->assertSee('Your licence keys')->assertSee('SECRET-ONE')->assertSee('SECRET-TWO');

        expect($response->headers->get('Cache-Control'))->toContain('no-store');
        $audit = DB::table('audit_events')->where('action', 'digital.license_keys_shown')->get();
        expect($audit)->toHaveCount(1)->and(json_encode($audit))->not->toContain('SECRET');
    });

    it('still shows the keys after the downloads have run out or the time has passed, but never after the right is revoked', function () {
        ($this->import)('BOOK-1', ['SECRET-ONE']);
        [, $right] = ($this->buy)(1);
        DB::table('entitlements')->where('id', $right->id)->update(['downloads_used' => 5, 'expires_at' => now()->subDay()]);

        $this->actingAs($this->owner)->post("/en/account/library/{$right->public_id}/keys")->assertOk()->assertSee('SECRET-ONE');
        DB::table('entitlements')->where('id', $right->id)->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->actingAs($this->owner)->post("/en/account/library/{$right->public_id}/keys")->assertSessionHasErrors('download')->assertDontSee('SECRET-ONE');
    });

    it('says the keys are not ready, rather than showing a blank, when none has been given yet', function () {
        [, $right] = ($this->buy)(1);
        ($this->import)('BOOK-1', []);
        DB::table('entitlements')->where('id', $right->id)->update(['keys_needed' => 1]);

        $this->actingAs($this->owner)->post("/en/account/library/{$right->public_id}/keys")->assertSessionHasErrors(['download' => 'Your keys are not ready yet. They will appear here as soon as they are.']);
    });

    it('treats somebody else\'s keys as not found, and remembers who asked', function () {
        ($this->import)('BOOK-1', ['SECRET-ONE']);
        [, $right] = ($this->buy)(1);

        $this->post("/en/account/library/{$right->public_id}/keys")->assertRedirect();   // not signed in
        $this->actingAs($this->stranger)->post("/en/account/library/{$right->public_id}/keys")->assertNotFound()->assertDontSee('SECRET-ONE');

        expect(DB::table('download_logs')->where('outcome', 'not_owner')->where('user_id', $this->stranger->id)->count())->toBe(1)->and(DB::table('audit_events')->where('action', 'digital.license_keys_shown')->count())->toBe(0);
    });

    it('lets a guest see their keys only inside the code session for that order', function () {
        ($this->import)('BOOK-1', ['SECRET-ONE']);
        [$order, $right] = ($this->buy)(1, null, 'BOOK-1', true);
        $link = fn (string $route) => URL::temporarySignedRoute($route, now()->addDay(), ['locale' => 'en', 'order' => $order->public_id]);

        $this->post("/en/orders/{$order->public_id}/downloads/{$right->public_id}/keys")->assertForbidden();   // no code yet
        $this->post($link('digital.guest.code'));
        $this->post($link('digital.guest.verify'), ['code' => app(FakeSmsGateway::class)->lastCode()]);
        $this->get($link('digital.guest.library'))->assertOk()->assertSee('Show my keys')->assertDontSee('SECRET-ONE');
        $this->post("/en/orders/{$order->public_id}/downloads/{$right->public_id}/keys")->assertOk()->assertSee('SECRET-ONE');

        [, $other] = ($this->buy)(1, $this->stranger);
        $this->post("/en/orders/{$order->public_id}/downloads/{$other->public_id}/keys")->assertNotFound();   // another order's keys through my order
    });
});

describe('staff', function () {
    it('see the counts and the import form only with the right permission, and import through the page', function () {
        ($this->import)('BOOK-1', ['K-1']);
        ($this->buy)(2);
        $page = '/admin/digital/assets/BOOK-1';

        $this->get($page)->assertRedirect();
        $this->actingAs($this->nobody, 'admin')->get($page)->assertForbidden();
        $this->actingAs($this->viewer, 'admin')->get($page)->assertOk()->assertSee('Licence keys')->assertSee('Owed, waiting')->assertDontSee('Add keys');
        $this->actingAs($this->viewer, 'admin')->post("{$page}/keys", ['file' => UploadedFile::fake()->createWithContent('k.txt', "A\nB")])->assertForbidden();
        $this->actingAs($this->nobody, 'admin')->post("{$page}/keys", ['file' => UploadedFile::fake()->createWithContent('k.txt', "A\nB")])->assertForbidden();

        $this->actingAs($this->manager, 'admin')->get($page)->assertOk()->assertSee('Add keys')->assertSee('There are 1 keys owed to customers and only 0 available');
        $this->actingAs($this->manager, 'admin')->post("{$page}/keys", ['file' => UploadedFile::fake()->createWithContent('k.txt', "K-2\nK-3\nK-2")])->assertSessionHas('status', '2 keys added, 1 already there, 0 not valid. 1 keys went straight to customers who were waiting.');
        $this->actingAs($this->manager, 'admin')->post("{$page}/keys", [])->assertSessionHasErrors('file');
        $this->actingAs($this->manager, 'admin')->post("{$page}/keys", ['file' => UploadedFile::fake()->createWithContent('k.txt', "\xff\xfe\x00")])->assertSessionHasErrors(['asset' => 'The file of keys must be saved as UTF-8 text.']);
    });

    it('is told by a log line, once an hour, when customers are waiting for more keys than there are', function () {
        ($this->import)('BOOK-1', ['K-1']);
        ($this->buy)(3);
        Log::spy();

        $first = app(CheckKeyStock::class)->handle();
        $second = app(CheckKeyStock::class)->handle();

        expect($first)->toHaveCount(1)->and($second)->toBe([]);
        Log::shouldHaveReceived('error')->withArgs(fn (string $m, array $c) => $m === 'digital.alert' && $c['signal'] === 'keys_short' && $c['waiting'] === 2)->once();
        Artisan::call('schedule:list');
        expect(Artisan::output())->toMatch('/\*\/15 \* \* \* \*\s+digital:check-keys/');
    });

    it('fills a right that was left owed keys, before it counts who is short, when the pool can cover it', function () {
        [, $right] = ($this->buy)(1);
        DB::table('entitlements')->where('id', $right->id)->update(['keys_needed' => 1]);
        DB::table('license_key_pools')->insert(['public_id' => strtolower((string) Str::ulid()), 'variant_id' => $this->book, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('license_keys')->insert(['pool_id' => DB::table('license_key_pools')->value('id'), 'key_encrypted' => Crypt::encryptString('K-9'), 'fingerprint' => ImportLicenseKeys::fingerprint('K-9'), 'status' => 'available', 'created_at' => now()]);   // a key that arrived without going through the import
        Log::spy();

        expect(app(CheckKeyStock::class)->handle())->toBe([])->and(($this->held)($right->id))->toBe(1);
        Log::shouldNotHaveReceived('error');
    });
});

describe('both languages', function () {
    it('show the keys pages and the staff section in Bangla with no missing text', function () {
        ($this->import)('BOOK-1', ['SECRET-ONE']);
        [, $right] = ($this->buy)(2);
        $this->owner->forceFill(['locale' => 'bn'])->save();
        $this->manager->forceFill(['locale' => 'bn'])->save();

        foreach ([['get', '/bn/account/library'], ['post', "/bn/account/library/{$right->public_id}/keys"]] as [$verb, $url]) {
            expect($this->actingAs($this->owner)->{$verb}($url)->assertOk()->getContent())->not->toContain('digital-products::');
        }
        $this->actingAs($this->owner)->get('/bn/account/library')->assertSee('আমার কী দেখান');
        expect($this->actingAs($this->manager, 'admin')->get('/admin/digital/assets/BOOK-1')->assertOk()->assertSee('লাইসেন্স কী')->getContent())->not->toContain('digital-products::');
    });
});
