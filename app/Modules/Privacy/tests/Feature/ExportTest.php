<?php

declare(strict_types=1);

use App\Modules\Privacy\Actions\ExpireExports;
use App\Modules\Privacy\Contracts\PersonalDataExporter;
use App\Modules\Privacy\Services\PersonalDataCatalog;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    privacyWorld($this);
    $this->amina = ($this->makeCustomer)('Amina Rahman', 'amina@example.com', '+8801711111111');
    $this->bob = ($this->makeCustomer)('Bob Karim', 'bob@example.com', '+8801822222222');
    $this->ask = fn (?array $who = null, bool $confirmed = true) => ($this->as)($who['user'] ?? $this->amina['user'], $confirmed)->post('/en/account/privacy/export');
    $this->request = fn (?array $who = null) => DB::table('privacy_requests')->where('user_id', ($who['user'] ?? $this->amina['user'])->id)->where('type', 'export')->orderByDesc('id')->first();
    $this->copyOf = fn (object $request) => json_decode(Crypt::decryptString(Storage::disk('private')->get($request->file_key)), true, flags: JSON_THROW_ON_ERROR);
});

describe('asking for a copy', function () {
    it('is for signed-in customers who have just confirmed it is them', function () {
        $this->post('/en/account/privacy/export')->assertRedirect('/en/login');
        ($this->ask)(null, confirmed: false)->assertRedirect('/en/account/confirm');

        expect(DB::table('privacy_requests')->count())->toBe(0);
    });

    it('builds the copy, keeps it encrypted, and tells the customer by email and text', function () {
        ($this->ask)()->assertRedirect('/en/account/privacy')->assertSessionHas('status');

        $request = ($this->request)();
        expect($request->status)->toBe('ready')->and($request->open_key)->toBeNull()->and($request->file_key)->not->toBeNull()->and($request->expires_at)->not->toBeNull();
        $raw = Storage::disk('private')->get($request->file_key);
        expect($raw)->not->toContain('amina@example.com')->and($raw)->not->toContain('Amina')->and($raw)->not->toContain('{');   // nothing readable at rest
        expect(fn () => json_decode($raw, true, flags: JSON_THROW_ON_ERROR))->toThrow(JsonException::class);

        Mail::assertSent(Mailable::class, fn ($m) => $m->hasTo('amina@example.com') && str_contains($m->text, '/en/account/privacy'));
        expect(collect($this->sms->sent())->where('to', '+8801711111111')->pluck('message')->join(' '))->toContain('your data copy is ready');
        expect(DB::table('audit_events')->whereIn('action', ['privacy.export_requested', 'privacy.export_ready'])->count())->toBe(2);
    });

    it('holds the customer\'s own data from every part of the shop', function () {
        ($this->ask)();
        $copy = ($this->copyOf)(($this->request)());

        expect($copy['account']['email'])->toBe('amina@example.com')->and($copy['account']['phone'])->toBe('+8801711111111')
            ->and($copy['profile']['profile']['name'])->toBe('Amina Rahman')->and($copy['profile']['addresses'])->toHaveCount(1)->and($copy['profile']['addresses'][0]['address'])->toContain('House 5')
            ->and($copy['orders'])->toHaveCount(1)->and($copy['orders'][0]['order_number'])->toBe($this->amina['order']->order_number)->and($copy['orders'][0]['items'][0]['name'])->not->toBe('')
            ->and($copy['orders'][0]['total_minor'])->toBe(35000)->and($copy['downloads']['rights'])->toHaveCount(1)->and($copy['downloads']['rights'][0]['product'])->toBe('Cooking e-book')
            ->and($copy['messages']['marketing'])->toBe(['email' => true, 'sms' => true, 'push' => false])->and($copy['messages']['quiet_hours'])->toBe(['from' => '22:00', 'to' => '07:00'])
            ->and($copy['messages']['consent_history'])->toHaveCount(2)->and($copy)->toHaveKeys(['bookings', 'generated_at', 'request']);
    });

    it('never holds anyone else\'s data', function () {
        ($this->ask)();
        $everything = json_encode(($this->copyOf)(($this->request)()), JSON_UNESCAPED_UNICODE);

        foreach (['bob@example.com', '+8801822222222', 'Bob Karim', $this->bob['order']->order_number, $this->bob['user']->public_id, $this->bob['right']->public_id] as $theirs) {
            expect($everything)->not->toContain($theirs);
        }
        expect($everything)->toContain('amina@example.com');
    });

    it('holds only the customer\'s own download history', function () {
        DB::table('download_logs')->insert([
            ['entitlement_id' => $this->amina['right']->id, 'user_id' => $this->amina['user']->id, 'outcome' => 'not_owner', 'ip' => '203.0.113.9', 'user_agent' => 'Firefox', 'created_at' => now()],
            ['entitlement_id' => $this->bob['right']->id, 'user_id' => $this->bob['user']->id, 'outcome' => 'limit_reached', 'ip' => '198.51.100.7', 'user_agent' => 'Chrome', 'created_at' => now()],
            ['entitlement_id' => $this->bob['right']->id, 'user_id' => $this->bob['user']->id, 'outcome' => 'revoked', 'ip' => '198.51.100.8', 'user_agent' => 'Chrome', 'created_at' => now()],
        ]);
        ($this->ask)();
        $copy = ($this->copyOf)(($this->request)());

        expect($copy['downloads']['history'])->toHaveCount(1)->and($copy['downloads']['history'][0]['outcome'])->toBe('not_owner')->and(json_encode($copy))->not->toContain('198.51.100');
    });

    it('does not put the keys or the files themselves in the copy', function () {
        ($this->upload)('BOOK-1', 'Cooking.pdf');
        ($this->ask)();
        $everything = json_encode(($this->copyOf)(($this->request)()));

        expect($everything)->not->toContain('%PDF')->and($everything)->not->toContain('storage_key');
    });

    it('can be asked for once a day, and one at a time', function () {
        ($this->ask)();
        ($this->ask)()->assertSessionHasErrors('privacy');
        expect(DB::table('privacy_requests')->where('type', 'export')->count())->toBe(1);

        $this->travel(25)->hours();
        ($this->ask)()->assertSessionHasNoErrors();
        expect(DB::table('privacy_requests')->where('type', 'export')->count())->toBe(2);
    });

    it('lets the database refuse a second open request even when two come at once', function () {
        DB::table('privacy_requests')->insert(['public_id' => strtolower((string) Str::ulid()), 'user_id' => $this->amina['user']->id, 'type' => 'export', 'status' => 'requested', 'open_key' => $this->amina['user']->id.':export', 'requested_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        ($this->ask)()->assertSessionHasErrors('privacy');   // already_open, found by the unique key
        expect(fn () => DB::table('privacy_requests')->insert(['public_id' => strtolower((string) Str::ulid()), 'user_id' => $this->amina['user']->id, 'type' => 'export', 'status' => 'requested', 'open_key' => $this->amina['user']->id.':export', 'requested_at' => now(), 'created_at' => now(), 'updated_at' => now()]))
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('does not count a copy that could not be made, and says so without leaking details', function () {
        $broken = new class implements PersonalDataExporter
        {
            public function section(): string
            {
                return 'broken';
            }

            public function export(int $userId): array
            {
                throw new RuntimeException('secret detail about +8801711111111');
            }
        };
        app(PersonalDataCatalog::class)->addExporter($broken::class);
        app()->bind($broken::class, fn () => $broken);

        ($this->ask)();
        $request = ($this->request)();
        expect($request->status)->toBe('failed')->and($request->open_key)->toBeNull()->and($request->file_key)->toBeNull()->and($request->failure)->toBe('RuntimeException')->and(Storage::disk('private')->allFiles('privacy'))->toBe([]);

        ($this->ask)()->assertSessionHasNoErrors();   // a failure does not use up the day
    });
});

describe('downloading a copy', function () {
    beforeEach(function () {
        ($this->ask)();
        $this->ready = ($this->request)();
        $this->url = "/en/account/privacy/exports/{$this->ready->public_id}/download";
    });

    it('gives the owner their file as a plain download that is never kept by a cache, and records it', function () {
        $response = ($this->as)($this->amina['user'])->get($this->url)->assertOk();

        expect($response->headers->get('Content-Type'))->toContain('application/json')->and($response->headers->get('Content-Disposition'))->toContain('attachment')->and($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and(json_decode($response->getContent(), true)['account']['email'])->toBe('amina@example.com');
        expect(DB::table('audit_events')->where('action', 'privacy.export_downloaded')->count())->toBe(1);
    });

    it('needs a fresh sign-in confirmation, and a sign-in at all', function () {
        ($this->as)($this->amina['user'], confirmed: false)->get($this->url)->assertRedirect('/en/account/confirm');
        auth('web')->logout();
        $this->get($this->url)->assertRedirect('/en/login');
    });

    it('is "not found" for another customer, however they got the address', function () {
        ($this->as)($this->bob['user'])->get($this->url)->assertNotFound();
        ($this->as)($this->bob['user'])->get('/en/account/privacy/exports/'.str_repeat('0', 26).'/download')->assertNotFound();
        expect(DB::table('audit_events')->where('action', 'privacy.export_downloaded')->count())->toBe(0);
    });

    it('stops working after the days are up, and the file is deleted', function () {
        $this->travel(8)->days();
        ($this->as)($this->amina['user'])->get($this->url)->assertRedirect('/en/account/privacy')->assertSessionHasErrors('privacy');

        expect(app(ExpireExports::class)->handle())->toBe(1)->and(app(ExpireExports::class)->handle())->toBe(0);
        $request = DB::table('privacy_requests')->where('id', $this->ready->id)->first();
        expect($request->status)->toBe('expired')->and($request->file_key)->toBeNull()->and(Storage::disk('private')->allFiles('privacy'))->toBe([]);
        ($this->as)($this->amina['user'])->get($this->url)->assertRedirect('/en/account/privacy')->assertSessionHasErrors('privacy');
    });

    it('keeps a copy that is still valid when the clean-up runs', function () {
        $this->travel(3)->days();

        expect(app(ExpireExports::class)->handle())->toBe(0)->and(Storage::disk('private')->allFiles('privacy'))->toHaveCount(1);
    });

    it('cannot be read without the application key', function () {
        $raw = Storage::disk('private')->get($this->ready->file_key);
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');

        expect(fn () => Crypt::decryptString($raw))->toThrow(DecryptException::class);
    });
});

describe('the privacy page', function () {
    it('lists only the customer\'s own requests', function () {
        ($this->ask)();
        ($this->ask)($this->bob);

        $html = ($this->as)($this->amina['user'])->get('/en/account/privacy')->assertOk()->getContent();
        expect(substr_count($html, 'Copy of my data'))->toBe(1)->and(substr_count($html, '/download'))->toBe(1);
        $bobsId = DB::table('privacy_requests')->where('user_id', $this->bob['user']->id)->value('public_id');
        expect($html)->not->toContain($bobsId);
    });

    it('is shown in English and in Bangla with no missing words', function () {
        foreach (['en' => 'Privacy & your data', 'bn' => 'গোপনীয়তা ও আপনার তথ্য'] as $locale => $title) {
            $response = ($this->as)($this->amina['user'])->get("/{$locale}/account/privacy")->assertOk()->assertSee($title);
            expect($response->getContent())->not->toContain('privacy::');
        }
    });

    it('has the same words in English and Bangla in every file', function () {
        $keys = function (array $a, string $prefix = '') use (&$keys): array {
            $out = [];
            foreach ($a as $k => $v) {
                is_array($v) ? array_push($out, ...$keys($v, $prefix.$k.'.')) : $out[] = $prefix.$k;
            }

            return $out;
        };
        foreach (['account', 'admin', 'errors'] as $file) {
            $en = $keys(require app_path("Modules/Privacy/resources/lang/en/{$file}.php"));
            $bn = $keys(require app_path("Modules/Privacy/resources/lang/bn/{$file}.php"));
            expect(array_values(array_diff($en, $bn)))->toBe([])->and(array_values(array_diff($bn, $en)))->toBe([]);
        }
    });
});
