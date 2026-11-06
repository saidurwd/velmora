<?php

declare(strict_types=1);

use App\Modules\Catalog\Contracts\MalwareScanner;
use App\Modules\Customers\Contracts\CustomerProfiles;
use App\Modules\Engagement\Exceptions\EngagementException;
use App\Modules\Engagement\Http\Controllers\Web\SupportFileResponse;
use App\Modules\Engagement\Services\SupportUploads;
use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    shopWorld($this);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    Storage::fake('private');
    $this->user = function (string $name, string $email): User {
        $user = User::factory()->create(['name' => $name, 'email' => $email]);
        app(CustomerProfiles::class)->ensureForUser($user->id, $name, $email, null);

        return $user;
    };
    $this->amina = ($this->user)('Amina Rahman', 'amina@example.com');
    $this->bob = ($this->user)('Bob Karim', 'bob@example.com');
    $this->as = fn (?User $u = null) => $this->actingAs($u ?? $this->amina, 'web');
    $this->open = fn (array $over = [], ?User $as = null) => ($this->as)($as)->post('/en/account/support', $over + ['subject' => 'Where is my parcel?', 'category' => 'delivery', 'body' => 'It was meant to arrive on Monday and has not.']);
    $this->tickets = fn () => DB::table('support_tickets')->orderBy('id')->get();
    $this->first = fn () => DB::table('support_tickets')->orderBy('id')->first();
    $this->png = fn (string $name = 'screen.png') => UploadedFile::fake()->image($name, 120, 90);
    $this->pdf = fn (string $name = 'receipt.pdf') => UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
    $this->staffNote = fn (object $ticket, string $body = 'Check with the courier') => DB::table('support_messages')->insertGetId(['public_id' => strtolower((string) Str::ulid()), 'ticket_id' => $ticket->id, 'author_type' => 'staff', 'author_id' => catalogStaff(['support.reply'])->id, 'body' => $body, 'is_internal' => true, 'created_at' => now()]);
});

describe('who can write', function () {
    it('is for signed-in customers, while the contact page is for everyone', function () {
        foreach (['/en/account/support', '/en/account/support/new'] as $page) {
            $this->get($page)->assertRedirect('/en/login');
        }
        $this->post('/en/account/support', ['subject' => 'x', 'category' => 'other', 'body' => 'hello there'])->assertRedirect('/en/login');
        $this->get('/en/contact')->assertOk()->assertSee('Contact us');

        expect(($this->tickets)())->toHaveCount(0);
    });

    it('lets the customer pick only among their own orders', function () {
        $mine = ($this->orderFor)($this->amina->id, [$this->tee['variantId'] => 1]);
        $theirs = ($this->orderFor)($this->bob->id, [$this->tee['variantId'] => 1]);

        ($this->as)()->get('/en/account/support/new')->assertOk()->assertSee($mine->order_number)->assertDontSee($theirs->order_number);
    });
});

describe('opening a ticket', function () {
    it('keeps the words, a reference to quote, the order and the time the first answer is due', function () {
        $order = ($this->orderFor)($this->amina->id, [$this->tee['variantId'] => 1]);

        ($this->open)(['category' => 'order', 'order' => $order->public_id])->assertRedirect()->assertSessionHas('status');

        $t = ($this->first)();
        expect($t->reference)->toMatch('/^[A-HJKMNP-Z2-9]{4}-[A-HJKMNP-Z2-9]{4}$/')->and($t->status)->toBe('open')->and($t->user_id)->toBe($this->amina->id)->and($t->order_id)->toBe($order->id)->and($t->subject)->toBe('Where is my parcel?')
            ->and($t->locale)->toBe('en')->and(abs(now()->addHours(24)->diffInSeconds(CarbonImmutable::parse($t->sla_due_at, 'UTC'), false)))->toBeLessThan(5)->and($t->first_responded_at)->toBeNull()->and($t->assignee_id)->toBeNull();
        $m = DB::table('support_messages')->first();
        expect($m->author_type)->toBe('customer')->and($m->is_internal)->toBe(0)->and($m->body)->toBe('It was meant to arrive on Monday and has not.');
        expect(DB::table('audit_events')->where('action', 'support.ticket_opened')->count())->toBe(1);
    });

    it('is refused for someone else\'s order, a bad subject or kind, and words that are too short or too long', function () {
        $this->withoutMiddleware(ThrottleRequests::class);   // the daily limit has its own test
        $theirs = ($this->orderFor)($this->bob->id, [$this->tee['variantId'] => 1]);

        ($this->open)(['order' => $theirs->public_id])->assertSessionHasErrors('support');
        ($this->open)(['subject' => 'Hi'])->assertSessionHasErrors('subject');
        ($this->open)(['category' => 'nonsense'])->assertSessionHasErrors('category');
        ($this->open)(['body' => 'Help'])->assertSessionHasErrors('body');
        ($this->open)(['body' => str_repeat('x', 5001)])->assertSessionHasErrors('body');
        ($this->open)(['order' => 'short'])->assertSessionHasErrors('order');

        expect(($this->tickets)())->toHaveCount(0);
    });

    it('limits how many can be open at once, and how many can be sent in a day', function () {
        config(['engagement.support.max_open_tickets' => 2]);
        ($this->open)()->assertSessionHasNoErrors();
        ($this->open)()->assertSessionHasNoErrors();
        ($this->open)()->assertSessionHasErrors('support');
        expect(($this->tickets)())->toHaveCount(2);

        expect(collect(Route::getRoutes()->getByName('account.support.store')->gatherMiddleware())->contains('throttle:support-new'))->toBeTrue();
        $limit = RateLimiter::limiter('support-new')(request());
        expect($limit->maxAttempts)->toBe(5)->and($limit->decaySeconds)->toBe(86400);
    });

    it('notes the language it was written in, for the answer to come back in', function () {
        ($this->open)([], null)->assertSessionHasNoErrors();
        ($this->as)()->post('/bn/account/support', ['subject' => 'আমার পার্সেল কোথায়?', 'category' => 'delivery', 'body' => 'সোমবার আসার কথা ছিল, এখনও আসেনি।'])->assertSessionHasNoErrors();

        expect(($this->tickets)()->pluck('locale')->all())->toBe(['en', 'bn']);
    });
});

describe('the conversation', function () {
    it('is shown to its owner with the shop\'s answers, and never with a note kept for the team', function () {
        ($this->open)();
        $t = ($this->first)();
        ($this->staffNote)($t, 'Secret team note about the courier');
        DB::table('support_messages')->insert(['public_id' => strtolower((string) Str::ulid()), 'ticket_id' => $t->id, 'author_type' => 'staff', 'author_id' => catalogStaff(['support.reply'])->id, 'body' => 'It is out for delivery today.', 'is_internal' => false, 'created_at' => now()]);

        $html = ($this->as)()->get("/en/account/support/{$t->public_id}")->assertOk()->assertSee('Where is my parcel?')->assertSee($t->reference)->assertSee('It is out for delivery today.')->assertSee('The shop')->getContent();
        expect($html)->not->toContain('Secret team note')->and($html)->not->toContain('Team only');
        ($this->as)()->get('/en/account/support')->assertOk()->assertSee('Where is my parcel?')->assertSee($t->reference);
    });

    it('is "not found" for anyone else, and for something that does not exist', function () {
        ($this->open)();
        $t = ($this->first)();

        ($this->as)($this->bob)->get("/en/account/support/{$t->public_id}")->assertNotFound();
        ($this->as)($this->bob)->post("/en/account/support/{$t->public_id}/reply", ['body' => 'I am not the owner'])->assertNotFound();
        ($this->as)($this->bob)->post("/en/account/support/{$t->public_id}/close")->assertNotFound();
        ($this->as)()->get('/en/account/support/'.str_repeat('0', 26))->assertNotFound();
        ($this->as)($this->bob)->get('/en/account/support')->assertOk()->assertDontSee('Where is my parcel?');

        expect(DB::table('support_messages')->count())->toBe(1)->and(($this->first)()->status)->toBe('open');
    });

    it('goes back to the shop when the customer replies to an answer, and the order link is theirs', function () {
        $order = ($this->orderFor)($this->amina->id, [$this->tee['variantId'] => 1]);
        ($this->open)(['order' => $order->public_id]);
        $t = ($this->first)();
        DB::table('support_tickets')->where('id', $t->id)->update(['status' => 'answered']);

        ($this->as)()->post("/en/account/support/{$t->public_id}/reply", ['body' => 'Thank you, one more question.'])->assertSessionHas('status');

        expect(($this->first)()->status)->toBe('open')->and(DB::table('support_messages')->where('ticket_id', $t->id)->count())->toBe(2);
        ($this->as)()->get("/en/account/support/{$t->public_id}")->assertSee($order->order_number)->assertSee("/en/account/orders/{$order->public_id}", false);
    });

    it('can be closed by the customer, and opened again by a reply within the window but not after', function () {
        ($this->open)();
        $t = ($this->first)();

        ($this->as)()->post("/en/account/support/{$t->public_id}/close")->assertSessionHas('status');
        ($this->as)()->post("/en/account/support/{$t->public_id}/close")->assertSessionHasErrors('support');
        expect(($this->first)()->status)->toBe('closed')->and(($this->first)()->closed_at)->not->toBeNull();

        $this->travel(10)->days();
        ($this->as)()->post("/en/account/support/{$t->public_id}/reply", ['body' => 'Actually it is still not here.'])->assertSessionHasNoErrors();
        expect(($this->first)()->status)->toBe('open')->and(($this->first)()->closed_at)->toBeNull();

        ($this->as)()->post("/en/account/support/{$t->public_id}/close");
        $this->travel(15)->days();
        ($this->as)()->post("/en/account/support/{$t->public_id}/reply", ['body' => 'Another thing, much later.'])->assertSessionHasErrors('support');
        expect(($this->first)()->status)->toBe('closed');
    });

    it('refuses an empty or too-long reply and limits how often one can reply', function () {
        ($this->open)();
        $t = ($this->first)();

        ($this->as)()->post("/en/account/support/{$t->public_id}/reply", ['body' => 'ok'])->assertSessionHasErrors('body');
        ($this->as)()->post("/en/account/support/{$t->public_id}/reply", ['body' => str_repeat('y', 5001)])->assertSessionHasErrors('body');
        expect(collect(Route::getRoutes()->getByName('account.support.reply')->gatherMiddleware())->contains('throttle:support-reply'))->toBeTrue();
        expect(RateLimiter::limiter('support-reply')(request())->maxAttempts)->toBe(20);
    });

    it('keeps what was typed as text: nothing in it is run as a page', function () {
        ($this->open)(['subject' => '<script>alert(1)</script>', 'body' => '<img src=x onerror=alert(1)> please help me']);
        $t = ($this->first)();

        $html = ($this->as)()->get("/en/account/support/{$t->public_id}")->getContent();
        expect($html)->not->toContain('<script>alert(1)')->and($html)->not->toContain('<img src=x')->and($html)->toContain('&lt;script&gt;');
    });
});

describe('files sent with a message', function () {
    it('keeps a picture as a clean WebP, and a PDF as it is, on the private disk under random names', function () {
        ($this->open)(['files' => [($this->png)('my screen shot.png'), ($this->pdf)('my receipt.pdf')]])->assertSessionHasNoErrors();

        $files = DB::table('support_attachments')->orderBy('id')->get();
        expect($files)->toHaveCount(2)->and($files[0]->mime)->toBe('image/webp')->and($files[0]->original_name)->toBe('my screen shot.webp')->and($files[1]->mime)->toBe('application/pdf')->and($files[1]->original_name)->toBe('my receipt.pdf');
        foreach ($files as $f) {
            Storage::disk('private')->assertExists($f->storage_key);
            expect($f->storage_key)->toStartWith('support/')->and($f->storage_key)->not->toContain('receipt')->not->toContain('screen');
        }
        expect(getimagesizefromstring(Storage::disk('private')->get($files[0]->storage_key))[2])->toBe(IMAGETYPE_WEBP);
    });

    it('refuses what is not really a picture or a PDF, whatever it is called', function () {
        $script = UploadedFile::fake()->createWithContent('photo.jpg', "<?php system(\$_GET['c']); ?>");
        $html = UploadedFile::fake()->createWithContent('invoice.pdf', '<html><script>alert(1)</script></html>');
        $exe = UploadedFile::fake()->createWithContent('tool.exe', "MZ\x90\x00\x03");
        $fakeImage = UploadedFile::fake()->createWithContent('pic.png', 'this is just text');

        foreach ([$script, $html, $exe, $fakeImage] as $bad) {
            ($this->open)(['files' => [$bad]])->assertSessionHasErrors('support');
        }

        expect(($this->tickets)())->toHaveCount(0)->and(DB::table('support_attachments')->count())->toBe(0)->and(Storage::disk('private')->allFiles())->toBe([]);
    });

    it('refuses too many files and files that are too big', function () {
        ($this->open)(['files' => [($this->png)('a.png'), ($this->png)('b.png'), ($this->png)('c.png'), ($this->png)('d.png')]])->assertSessionHasErrors();
        ($this->open)(['files' => [UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf')]])->assertSessionHasErrors();

        expect(($this->tickets)())->toHaveCount(0)->and(Storage::disk('private')->allFiles())->toBe([]);
    });

    it('asks the virus-scan hook first and refuses a file it does not like', function () {
        $this->app->instance(MalwareScanner::class, new class implements MalwareScanner
        {
            public function isClean(string $bytes, string $originalName): bool
            {
                return false;
            }
        });

        ($this->open)(['files' => [($this->png)()]])->assertSessionHasErrors('support');

        expect(($this->tickets)())->toHaveCount(0)->and(Storage::disk('private')->allFiles())->toBe([]);
    });

    it('is undone as a whole when a file cannot be kept: no ticket, no message, no stray row', function () {
        config(['engagement.support.disk' => 'no-such-disk']);
        $this->withoutExceptionHandling();

        expect(fn () => ($this->open)(['files' => [($this->png)()]]))->toThrow(InvalidArgumentException::class);

        expect(($this->tickets)())->toHaveCount(0)->and(DB::table('support_messages')->count())->toBe(0)->and(DB::table('support_attachments')->count())->toBe(0);
    });
});

describe('the upload checker on its own', function () {
    it('holds its limits even when called without the form: count, size, type and the scan', function () {
        $uploads = app(SupportUploads::class);
        $reason = fn (array $files) => (function () use ($uploads, $files) {
            try {
                $uploads->prepare($files);
            } catch (EngagementException $e) {
                return $e->reason;
            }

            return 'accepted';
        })();

        expect($reason([($this->png)('a.png'), ($this->png)('b.png'), ($this->png)('c.png'), ($this->png)('d.png')]))->toBe('too_many_files')
            ->and($reason([UploadedFile::fake()->createWithContent('big.pdf', "%PDF-1.4\n".str_repeat('x', 5_300_000))]))->toBe('file_too_large')
            ->and($reason([UploadedFile::fake()->createWithContent('x.jpg', 'plain text')]))->toBe('file_type')
            ->and($reason([UploadedFile::fake()->createWithContent('empty.pdf', '')]))->toBe('file_unreadable')
            ->and($reason([($this->png)('a.png'), ($this->pdf)()]))->toBe('accepted');
    });
});

describe('the file response on its own', function () {
    it('tells the browser not to guess the type, not to keep a copy, and to treat a PDF as a download', function () {
        Storage::disk('private')->put('support/x.pdf', '%PDF-1.4 x');
        Storage::disk('private')->put('support/y.webp', 'webpdata');

        $pdf = SupportFileResponse::stream(['key' => 'support/x.pdf', 'mime' => 'application/pdf', 'name' => 'a "weird" name.pdf']);
        $img = SupportFileResponse::stream(['key' => 'support/y.webp', 'mime' => 'image/webp', 'name' => 'y.webp']);

        expect($pdf->headers->get('X-Content-Type-Options'))->toBe('nosniff')->and($pdf->headers->get('Content-Disposition'))->toStartWith('attachment')->and($pdf->headers->get('Content-Disposition'))->not->toContain('"weird"')
            ->and($pdf->headers->get('Cache-Control'))->toContain('no-store')->and($pdf->headers->get('Content-Security-Policy'))->toContain('sandbox')->and($img->headers->get('Content-Disposition'))->toStartWith('inline');
    });
});

describe('downloading a file', function () {
    beforeEach(function () {
        ($this->open)(['files' => [($this->png)(), ($this->pdf)()]]);
        $this->files = DB::table('support_attachments')->orderBy('id')->get();
    });

    it('gives the owner a picture to view and a PDF to download, with the browser told not to guess or keep it', function () {
        $img = ($this->as)()->get("/en/account/support/files/{$this->files[0]->public_id}")->assertOk();
        $pdf = ($this->as)()->get("/en/account/support/files/{$this->files[1]->public_id}")->assertOk();

        expect($img->headers->get('Content-Type'))->toContain('image/webp')->and($img->headers->get('Content-Disposition'))->toStartWith('inline')->and($pdf->headers->get('Content-Type'))->toContain('application/pdf')->and($pdf->headers->get('Content-Disposition'))->toStartWith('attachment');
        foreach ([$img, $pdf] as $r) {
            expect($r->headers->get('X-Content-Type-Options'))->toBe('nosniff')->and($r->headers->get('Cache-Control'))->toContain('no-store')->and($r->headers->get('Content-Security-Policy'))->toContain('sandbox');
        }
    });

    it('is "not found" for another customer, a guest is sent to sign in, and an unknown file is not found', function () {
        ($this->as)($this->bob)->get("/en/account/support/files/{$this->files[0]->public_id}")->assertNotFound();
        ($this->as)()->get('/en/account/support/files/'.str_repeat('0', 26))->assertNotFound();
        auth('web')->logout();
        $this->get("/en/account/support/files/{$this->files[0]->public_id}")->assertRedirect('/en/login');
    });

    it('never shows the customer a file that is on a note kept for the team', function () {
        $t = ($this->first)();
        $noteId = ($this->staffNote)($t);
        DB::table('support_attachments')->insert(['public_id' => 'noteattach'.str_pad('1', 16, '0'), 'message_id' => $noteId, 'original_name' => 'internal.pdf', 'mime' => 'application/pdf', 'size_bytes' => 10, 'storage_key' => 'support/internal.pdf', 'created_at' => now()]);
        Storage::disk('private')->put('support/internal.pdf', '%PDF-1.4');

        ($this->as)()->get('/en/account/support/files/noteattach'.str_pad('1', 16, '0'))->assertNotFound();
    });
});

describe('words', function () {
    it('shows every page in English and in Bangla with no missing words', function () {
        ($this->open)(['files' => [($this->png)()]]);
        $t = ($this->first)();

        foreach (['en', 'bn'] as $locale) {
            foreach (["/{$locale}/account/support", "/{$locale}/account/support/new", "/{$locale}/account/support/{$t->public_id}", "/{$locale}/contact"] as $page) {
                expect(($this->as)()->get($page)->assertOk()->getContent())->not->toContain('engagement::');
            }
        }
        ($this->as)()->get('/bn/account/support')->assertSee('আমাদের কাছ থেকে সহায়তা');
    });

    it('has the same words in English and Bangla', function () {
        $keys = function (array $a, string $prefix = '') use (&$keys): array {
            $out = [];
            foreach ($a as $k => $v) {
                is_array($v) ? array_push($out, ...$keys($v, $prefix.$k.'.')) : $out[] = $prefix.$k;
            }

            return $out;
        };
        $en = $keys(require app_path('Modules/Engagement/resources/lang/en/support.php'));
        $bn = $keys(require app_path('Modules/Engagement/resources/lang/bn/support.php'));

        expect(array_values(array_diff($en, $bn)))->toBe([])->and(array_values(array_diff($bn, $en)))->toBe([]);
    });
});
