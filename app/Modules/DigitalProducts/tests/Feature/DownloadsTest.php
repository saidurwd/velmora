<?php

declare(strict_types=1);

use App\Modules\DigitalProducts\Actions\DownloadDigitalFile;
use App\Modules\DigitalProducts\Models\Entitlement;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Notifications\Sms\FakeSmsGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    digitalWorld($this);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    config(['services.sms.driver' => 'fake']);
    $this->sms = app(FakeSmsGateway::class);
    $this->owner = User::factory()->create(['email' => 'owner@example.com']);
    $this->stranger = User::factory()->create(['email' => 'stranger@example.com']);

    /** A paid order of a book for the owner, with its file uploaded. Returns [order, entitlement row]. */
    $this->bought = function (string $sku = 'BOOK-1', ?User $for = null, bool $file = true, string $phone = '+8801712345678', bool $guest = false) {
        $order = ($this->place)([$sku === 'BOOK-1' ? $this->book : $this->book2 => 1], $guest ? null : ($for ?? $this->owner)->id, $phone);
        ($this->pay)($order->id);
        if ($file) {
            ($this->upload)($sku, 'Cooking Book.pdf');
        }

        return [$order, DB::table('entitlements')->where('order_id', $order->id)->first()];
    };
    $this->log = fn (?string $outcome = null) => DB::table('download_logs')->when($outcome, fn ($q) => $q->where('outcome', $outcome))->orderBy('id')->get();
    $this->used = fn (int $id) => (int) DB::table('entitlements')->where('id', $id)->value('downloads_used');
});

describe('my downloads (signed in)', function () {
    it('lists what I bought, with the file, the version and how many downloads are left, and nothing of anybody else\'s', function () {
        [$order] = ($this->bought)();
        ($this->bought)('BOOK-2', $this->stranger, true);
        $this->get('/en/account/library')->assertRedirect();   // not signed in

        $html = $this->actingAs($this->owner)->get('/en/account/library')->assertOk()->assertSee('My downloads')->assertSee('Cooking e-book')->assertSee('Cooking Book.pdf')->assertSee('version 1')->assertSee('5 downloads left.')
            ->assertSee($order->order_number)->assertDontSee('Garden e-book')->assertSee('Ready')->getContent();

        $key = DB::table('digital_asset_versions')->value('storage_key');
        expect($html)->not->toContain($key)->not->toContain('files.test')->not->toContain('digital/');   // no storage path or address is ever in a page
        $this->actingAs($this->stranger)->get('/en/account/library')->assertOk()->assertSee('Garden e-book')->assertDontSee('Cooking e-book');
    });

    it('says plainly when there is nothing, and when the file is not ready', function () {
        $this->actingAs($this->owner)->get('/en/account/library')->assertOk()->assertSee('You have no digital downloads yet');
        ($this->bought)('BOOK-1', null, false);

        $this->actingAs($this->owner)->get('/en/account/library')->assertOk()->assertSee('Not ready yet')->assertSee('nothing has been used up')->assertDontSee('>Download<', false);
    });

    it('reads in a fixed number of queries however many downloads there are', function () {
        ($this->bought)();
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->owner)->get('/en/account/library')->assertOk();

            return count(DB::getQueryLog());
        };
        $this->actingAs($this->owner)->get('/en/account/library');   // warm up
        $few = $count();
        ($this->bought)('BOOK-2');
        foreach (range(1, 3) as $i) {
            ($this->pay)(($this->place)([$this->book => 1], $this->owner->id)->id);
        }

        expect(DB::table('entitlements')->count())->toBe(5)->and($count())->toBe($few);
    });
});

describe('downloading', function () {
    it('sends me to a short-lived signed address, uses up one download and writes it down', function () {
        [, $right] = ($this->bought)();

        $response = $this->actingAs($this->owner)->withHeaders(['User-Agent' => 'TestBrowser/1.0'])->post("/en/account/library/{$right->public_id}/download")->assertStatus(303);

        $location = (string) $response->headers->get('Location');
        $key = DB::table('digital_asset_versions')->value('storage_key');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        expect($location)->toStartWith('https://files.test/'.$key)->and((int) $query['exp'] - now()->getTimestamp())->toBeBetween(55, 61)
            ->and($query['disp'])->toContain('attachment')->toContain('Cooking%20Book.pdf')->and($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and(($this->used)($right->id))->toBe(1);
        $log = ($this->log)()[0];
        expect($log->outcome)->toBe('granted')->and($log->user_id)->toBe($this->owner->id)->and($log->user_agent)->toBe('TestBrowser/1.0')->and($log->version_id)->not->toBeNull()->and($log->ip)->not->toBeNull();
        $this->actingAs($this->owner)->get('/en/account/library')->assertSee('4 downloads left.');
        expect(json_encode(DB::table('download_logs')->get()))->not->toContain('files.test')->not->toContain($key);   // the address is never stored
    });

    it('gives me the newest version of the file', function () {
        [, $right] = ($this->bought)();
        $new = ($this->upload)('BOOK-1', 'Cooking Book v2.pdf');

        $location = (string) $this->actingAs($this->owner)->post("/en/account/library/{$right->public_id}/download")->headers->get('Location');

        expect($location)->toContain($new->storage_key)->and($new->version)->toBe(2);
        $this->actingAs($this->owner)->get('/en/account/library')->assertSee('Cooking Book v2.pdf')->assertSee('version 2');
        expect(($this->log)('granted')[0]->version_id)->toBe($new->id);
    });

    it('never goes past the limit, and each refusal says why, is written down, and uses up nothing', function () {
        [, $right] = ($this->bought)();
        DB::table('entitlements')->where('id', $right->id)->update(['download_limit' => 2]);
        $go = fn () => $this->actingAs($this->owner)->post("/en/account/library/{$right->public_id}/download");

        $go()->assertStatus(303);
        $go()->assertStatus(303);
        $go()->assertRedirect()->assertSessionHasErrors(['download' => 'You have used all the downloads for this product. Please contact us if you need another.']);
        $go()->assertSessionHasErrors('download');

        expect(($this->used)($right->id))->toBe(2)->and(($this->log)('granted'))->toHaveCount(2)->and(($this->log)('limit_reached'))->toHaveCount(2);
        $this->actingAs($this->owner)->get('/en/account/library')->assertSee('No downloads left');
    });

    it('refuses a revoked right and an expired one, with the reason, and uses up nothing', function () {
        [, $revoked] = ($this->bought)();
        [, $expired] = ($this->bought)('BOOK-2');
        DB::table('entitlements')->where('id', $revoked->id)->update(['status' => 'revoked', 'revoked_at' => now(), 'revoked_reason' => 'refund']);
        DB::table('entitlements')->where('id', $expired->id)->update(['expires_at' => now()->subMinute()]);

        $this->actingAs($this->owner)->post("/en/account/library/{$revoked->public_id}/download")->assertSessionHasErrors(['download' => 'This download is no longer available. Please contact us if you think this is a mistake.']);
        $this->actingAs($this->owner)->post("/en/account/library/{$expired->public_id}/download")->assertSessionHasErrors(['download' => 'The time to download this has passed. Please contact us.']);

        expect(($this->used)($revoked->id))->toBe(0)->and(($this->used)($expired->id))->toBe(0)->and(($this->log)('revoked'))->toHaveCount(1)->and(($this->log)('expired'))->toHaveCount(1)->and(($this->log)('granted'))->toHaveCount(0);
    });

    it('does not charge a download when there is no file yet', function () {
        [, $right] = ($this->bought)('BOOK-1', null, false);

        $this->actingAs($this->owner)->post("/en/account/library/{$right->public_id}/download")->assertSessionHasErrors(['download' => 'The file is not ready yet. Nothing was used up; please try again later.']);

        expect(($this->used)($right->id))->toBe(0)->and(($this->log)('no_file'))->toHaveCount(1);
    });

    it('gives the download back when the address cannot be made', function () {
        [, $right] = ($this->bought)();
        Storage::disk('private')->buildTemporaryUrlsUsing(fn () => throw new RuntimeException('storage is down'));

        expect(fn () => app(DownloadDigitalFile::class)->handle(Entitlement::query()->findOrFail($right->id), $this->owner->id, '127.0.0.1', 'x'))->toThrow(RuntimeException::class);

        expect(($this->used)($right->id))->toBe(0)->and(($this->log)('granted'))->toHaveCount(0);   // the counter and the log are one transaction
    });

    it('treats somebody else\'s download as not found, remembers who tried, and uses up nothing', function () {
        [, $right] = ($this->bought)();

        $this->post("/en/account/library/{$right->public_id}/download")->assertRedirect();   // not signed in: sign in first
        $this->actingAs($this->stranger)->post("/en/account/library/{$right->public_id}/download")->assertNotFound();
        $this->actingAs($this->owner)->post('/en/account/library/01zzzzzzzzzzzzzzzzzzzzzzzz/download')->assertNotFound();

        $log = ($this->log)('not_owner');
        expect($log)->toHaveCount(1)->and($log[0]->user_id)->toBe($this->stranger->id)->and(($this->used)($right->id))->toBe(0)->and(($this->log)())->toHaveCount(1);
    });

    it('is rate limited per person', function () {
        [, $right] = ($this->bought)();
        DB::table('entitlements')->where('id', $right->id)->update(['download_limit' => null]);

        foreach (range(1, 20) as $i) {
            $this->actingAs($this->owner)->post("/en/account/library/{$right->public_id}/download")->assertStatus(303);
        }
        $this->actingAs($this->owner)->post("/en/account/library/{$right->public_id}/download")->assertStatus(429);
    });
});

describe('a guest', function () {
    beforeEach(function () {
        [$this->order, $this->right] = ($this->bought)('BOOK-1', null, true, '+8801712345678', true);   // a guest order: nobody owns it
        $this->expires = now()->addDay();
        $this->link = fn (string $route = 'digital.guest.library', ?string $order = null, $expires = null) => URL::temporarySignedRoute($route, $expires ?? $this->expires, ['locale' => 'en', 'order' => $order ?? $this->order->public_id]);
        $this->code = function () {
            $this->post(($this->link)('digital.guest.code'))->assertRedirect();

            return $this->sms->lastCode();
        };
    });

    it('needs the link we signed, unchanged and not expired, for an order that has downloads', function () {
        $this->get("/en/orders/{$this->order->public_id}/downloads")->assertForbidden();
        $this->get(($this->link)().'x')->assertForbidden();
        $this->get(($this->link)('digital.guest.library', null, now()->subMinute()))->assertForbidden();
        $this->get(str_replace($this->order->public_id, ($this->bought)('BOOK-2', $this->stranger, false)[0]->public_id, ($this->link)()))->assertForbidden();   // another order's id with this signature
        $this->get(($this->link)('digital.guest.library', '01zzzzzzzzzzzzzzzzzzzzzzzz'))->assertNotFound();
        $physical = ($this->place)([$this->tee => 1]);
        $this->get(($this->link)('digital.guest.library', $physical->public_id))->assertNotFound();   // nothing to download there
        $this->get(($this->link)())->assertOk()->assertSee('Send me a code')->assertSee('ending in 678')->assertDontSee('Cooking Book.pdf');
    });

    it('opens its downloads only after the right code, and a wrong one says the same thing each time', function () {
        $code = ($this->code)();
        expect($code)->toMatch('/^\d{6}$/');
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->post(($this->link)('digital.guest.verify'), ['code' => $wrong])->assertRedirect();
        $this->get(($this->link)())->assertOk()->assertSee('That code did not work')->assertDontSee('Cooking Book.pdf');
        $this->post("/en/orders/{$this->order->public_id}/downloads/{$this->right->public_id}")->assertForbidden();   // no access without the code

        $this->post(($this->link)('digital.guest.verify'), ['code' => $code])->assertRedirect();
        $this->get(($this->link)())->assertOk()->assertSee('Cooking Book.pdf')->assertSee('5 downloads left.');
    });

    it('downloads once in, writes it down with no account, and stops after the session ends', function () {
        $this->post(($this->link)('digital.guest.verify'), ['code' => ($this->code)()]);

        $this->post("/en/orders/{$this->order->public_id}/downloads/{$this->right->public_id}")->assertStatus(303);
        $log = ($this->log)('granted');
        expect($log)->toHaveCount(1)->and($log[0]->user_id)->toBeNull()->and(($this->used)($this->right->id))->toBe(1);

        $this->travel(31)->minutes();
        $this->post("/en/orders/{$this->order->public_id}/downloads/{$this->right->public_id}")->assertForbidden();
        $this->get(($this->link)('digital.guest.library', null, now()->addDay()))->assertOk()->assertSee('Send me a code');
        expect(($this->used)($this->right->id))->toBe(1);
    });

    it('cannot use the code of one order on another, nor reach another order\'s download', function () {
        [$other, $otherRight] = ($this->bought)('BOOK-2', null, true, '+8801898765432', true);
        $this->post(($this->link)('digital.guest.verify'), ['code' => ($this->code)()]);   // this guest opens their own order

        $this->get(($this->link)('digital.guest.library', $other->public_id))->assertOk()->assertSee('Send me a code');   // the other order is still locked
        $this->post("/en/orders/{$other->public_id}/downloads/{$otherRight->public_id}")->assertForbidden();
        $this->post("/en/orders/{$this->order->public_id}/downloads/{$otherRight->public_id}")->assertNotFound();   // another order's download through my order
        expect(($this->log)('not_owner'))->toHaveCount(1)->and(($this->used)($otherRight->id))->toBe(0);
    });

    it('is told to wait when it asks for codes too fast, in the same words as at checkout', function () {
        $this->post(($this->link)('digital.guest.code'))->assertRedirect();
        $this->post(($this->link)('digital.guest.code'))->assertRedirect();

        $this->get(($this->link)())->assertOk()->assertSee('Please wait a minute before asking for another code.');
        expect($this->sms->sent())->toHaveCount(1);
    });
});

describe('the links to downloads', function () {
    it('are on the account order page and the confirmation page only for a paid order with downloads', function () {
        [$order] = ($this->bought)();
        $plain = ($this->place)([$this->tee => 1], $this->owner->id);
        $unpaid = ($this->place)([$this->book2 => 1], $this->owner->id);
        $thanks = fn (object $o) => URL::temporarySignedRoute('checkout.thanks', now()->addDay(), ['locale' => 'en', 'order' => $o->public_id]);

        $this->actingAs($this->owner)->get("/en/account/orders/{$order->public_id}")->assertOk()->assertSee('Your downloads')->assertSee('/en/account/library', false);
        $this->actingAs($this->owner)->get("/en/account/orders/{$plain->public_id}")->assertOk()->assertDontSee('Your downloads');
        $this->actingAs($this->owner)->get("/en/account/orders/{$unpaid->public_id}")->assertOk()->assertDontSee('Your downloads');
        $this->get($thanks($order))->assertOk()->assertSee('Your downloads')->assertSee('/en/account/library', false);

        $guestOrder = ($this->place)([$this->book2 => 1]);
        ($this->pay)($guestOrder->id);
        $page = $this->get($thanks($guestOrder))->assertOk()->assertSee('Your downloads')->getContent();
        preg_match('#href="([^"]*/downloads\?[^"]+)"#', $page, $m);
        $this->get(html_entity_decode($m[1] ?? ''))->assertOk()->assertSee('Send me a code');   // a guest gets a signed link, not the account page
    });
});

describe('both languages', function () {
    it('show My downloads and the guest page in Bangla with no missing text', function () {
        [$order] = ($this->bought)();
        $this->owner->forceFill(['locale' => 'bn'])->save();

        foreach (['/bn/account/library'] as $page) {
            $html = $this->actingAs($this->owner)->get($page)->assertOk()->getContent();
            expect($html)->not->toContain('digital-products::');
        }
        $this->actingAs($this->owner)->get('/bn/account/library')->assertSee('আমার ডাউনলোড')->assertSee('ডাউনলোড');
        $guestOrder = ($this->place)([$this->book2 => 1]);
        ($this->pay)($guestOrder->id);
        $html = $this->get(URL::temporarySignedRoute('digital.guest.library', now()->addDay(), ['locale' => 'bn', 'order' => $guestOrder->public_id]))->assertOk()->assertSee('কোড পাঠান')->getContent();
        expect($html)->not->toContain('digital-products::');
    });
});
