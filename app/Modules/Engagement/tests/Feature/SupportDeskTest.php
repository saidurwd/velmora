<?php

declare(strict_types=1);

use App\Modules\Customers\Contracts\CustomerProfiles;
use App\Modules\Engagement\Actions\CheckSupportHealth;
use App\Modules\Engagement\Privacy\SupportPersonalData;
use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Notifications\Mail\NotificationMail;
use App\Modules\Notifications\Sms\FakeSmsGateway;
use App\Modules\Privacy\Services\PersonalDataCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    shopWorld($this);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    Storage::fake('private');
    Mail::fake();
    config(['services.sms.driver' => 'fake']);
    $this->sms = app(FakeSmsGateway::class);
    $this->sms->fail = false;
    $this->customer = function (string $name, ?string $email, ?string $phone = null): User {
        $user = User::factory()->create(['name' => $name, 'email' => $email ?? 'none-'.uniqid().'@example.com', 'phone' => $phone]);
        app(CustomerProfiles::class)->ensureForUser($user->id, $name, $email, $phone);

        return $user;
    };
    $this->amina = ($this->customer)('Amina Rahman', 'amina@example.com', '+8801711111111');
    $this->bob = ($this->customer)('Bob Karim', 'bob@example.com');
    $this->agent = catalogStaff(['support.view', 'support.reply']);
    $this->lead = catalogStaff(['support.view', 'support.reply', 'support.manage']);
    $this->viewer = catalogStaff(['support.view']);
    $this->nobody = catalogStaff(['catalog.view']);
    $this->ticket = function (?User $u = null, array $over = []) {
        $u ??= $this->amina;
        $id = DB::table('support_tickets')->insertGetId($over + ['public_id' => strtolower((string) Str::ulid()), 'reference' => strtoupper(substr(md5(uniqid()), 0, 4).'-'.substr(md5(uniqid()), 0, 4)), 'user_id' => $u->id, 'subject' => 'Where is my parcel?', 'category' => 'delivery',
            'status' => 'open', 'locale' => 'en', 'sla_due_at' => now()->addHours(24), 'last_message_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('support_messages')->insert(['public_id' => strtolower((string) Str::ulid()), 'ticket_id' => $id, 'author_type' => 'customer', 'author_id' => $u->id, 'body' => 'It has not arrived yet.', 'is_internal' => false, 'created_at' => now()]);

        return DB::table('support_tickets')->where('id', $id)->first();
    };
    $this->row = fn (object $t) => DB::table('support_tickets')->where('id', $t->id)->first();
    $this->desk = fn (?User $as = null) => $this->actingAs($as ?? $this->agent, 'admin');
    $this->reply = fn (object $t, array $data = [], ?User $as = null) => ($this->desk)($as)->post("/admin/support/{$t->public_id}/reply", $data + ['body' => 'It is out for delivery today.']);
});

describe('who may do what', function () {
    it('keeps the desk from people without support permissions, and from customers', function () {
        $t = ($this->ticket)();

        $this->get('/admin/support')->assertRedirect();
        $this->actingAs($this->amina, 'web')->get('/admin/support')->assertRedirect();
        ($this->desk)($this->nobody)->get('/admin/support')->assertForbidden();
        ($this->desk)($this->nobody)->get("/admin/support/{$t->public_id}")->assertForbidden();
    });

    it('lets a viewer look but not answer, assign, close or manage anything', function () {
        $t = ($this->ticket)();

        ($this->desk)($this->viewer)->get('/admin/support')->assertOk();
        ($this->desk)($this->viewer)->get("/admin/support/{$t->public_id}")->assertOk()->assertDontSee('Your answer');
        ($this->reply)($t, [], $this->viewer)->assertForbidden();
        ($this->desk)($this->viewer)->post("/admin/support/{$t->public_id}/assign", ['assignee' => $this->viewer->id])->assertForbidden();
        ($this->desk)($this->viewer)->post("/admin/support/{$t->public_id}/close")->assertForbidden();
        ($this->desk)($this->viewer)->get('/admin/support/canned')->assertForbidden();
        ($this->desk)($this->viewer)->get('/admin/support/contact')->assertForbidden();

        expect(($this->row)($t)->status)->toBe('open')->and(DB::table('support_messages')->count())->toBe(1);
    });

    it('lets an agent answer and take a ticket for themselves, but not close it or manage the canned replies', function () {
        $t = ($this->ticket)();

        ($this->reply)($t)->assertSessionHasNoErrors();
        ($this->desk)()->post("/admin/support/{$t->public_id}/close")->assertForbidden();
        ($this->desk)()->get('/admin/support/canned')->assertForbidden();
        ($this->desk)()->post('/admin/support/canned', [])->assertForbidden();
    });
});

describe('the queue', function () {
    it('lists active tickets with the most urgent first, with the customer, the order and who has it', function () {
        $late = ($this->ticket)($this->amina, ['subject' => 'Late one', 'sla_due_at' => now()->subHours(2)]);
        ($this->ticket)($this->bob, ['subject' => 'Newer one', 'sla_due_at' => now()->addHours(20)]);
        ($this->ticket)($this->bob, ['subject' => 'Closed one', 'status' => 'closed', 'closed_at' => now()]);

        ($this->desk)()->get('/admin/support')->assertOk()->assertSeeInOrder(['Late one', 'Newer one'])->assertDontSee('Closed one')->assertSee('Amina Rahman')->assertSee('Late')->assertSee($late->reference);
    });

    it('filters by status, who has it, category and lateness, and finds a ticket by its reference', function () {
        $mine = ($this->ticket)($this->amina, ['subject' => 'Parcel alpha', 'assignee_id' => $this->agent->id, 'category' => 'payment']);
        ($this->ticket)($this->bob, ['subject' => 'Parcel beta', 'category' => 'order', 'sla_due_at' => now()->subHour()]);
        ($this->ticket)($this->bob, ['subject' => 'Parcel gamma', 'status' => 'answered', 'first_responded_at' => now()]);

        ($this->desk)()->get('/admin/support?assignee=mine')->assertSee('Parcel alpha')->assertDontSee('Parcel beta');
        ($this->desk)()->get('/admin/support?assignee=none')->assertSee('Parcel beta')->assertDontSee('Parcel alpha');
        ($this->desk)()->get('/admin/support?category=payment')->assertSee('Parcel alpha')->assertDontSee('Parcel beta');
        ($this->desk)()->get('/admin/support?overdue=1')->assertSee('Parcel beta')->assertDontSee('Parcel alpha');
        ($this->desk)()->get('/admin/support?status=answered')->assertSee('Parcel gamma')->assertDontSee('Parcel alpha');
        ($this->desk)()->get('/admin/support?q='.strtolower($mine->reference))->assertSee('Parcel alpha')->assertDontSee('Parcel beta');
        ($this->desk)()->get('/admin/support?status=bogus')->assertSessionHasErrors('status');
    });

    it('lists in a fixed number of queries as it grows', function () {
        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            ($this->desk)()->get('/admin/support')->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        ($this->ticket)();
        $queries();
        $few = $queries();
        foreach (range(1, 6) as $i) {
            ($this->ticket)(($this->customer)("Customer {$i}", "c{$i}@example.com"));
        }
        $queries();

        expect($queries())->toBe($few);
    });
});

describe('answering', function () {
    it('answers the customer: the ticket waits for them, the first answer is timed, the agent takes it, and one message goes out without the words', function () {
        $t = ($this->ticket)();

        ($this->reply)($t, ['body' => 'Your parcel is out for delivery today. Sorry for the wait.'])->assertSessionHas('status');

        $r = ($this->row)($t);
        expect($r->status)->toBe('answered')->and($r->first_responded_at)->not->toBeNull()->and($r->assignee_id)->toBe($this->agent->id)->and(DB::table('support_messages')->where('ticket_id', $t->id)->orderByDesc('id')->value('author_type'))->toBe('staff');
        Mail::assertSent(NotificationMail::class, 1);
        Mail::assertSent(NotificationMail::class, fn (NotificationMail $m) => $m->hasTo('amina@example.com') && str_contains($m->text, $t->reference) && str_contains($m->text, "/en/account/support/{$t->public_id}") && ! str_contains($m->text, 'out for delivery'));
        expect($this->sms->sent())->toHaveCount(0)->and(DB::table('audit_events')->where('action', 'support.staff_replied')->count())->toBe(1);
    });

    it('tells a customer who has no email by text, once', function () {
        $phoneOnly = ($this->customer)('Karim', null, '+8801733333333');
        $t = ($this->ticket)($phoneOnly);

        ($this->reply)($t);

        Mail::assertNothingSent();
        expect($this->sms->sent())->toHaveCount(1)->and($this->sms->sent()[0]['to'])->toBe('+8801733333333')->and($this->sms->sent()[0]['message'])->toContain($t->reference)->and($this->sms->sent()[0]['message'])->not->toContain('out for delivery');
    });

    it('keeps the first-answer time from the first answer only, and the ticket with whoever took it first', function () {
        $t = ($this->ticket)();
        ($this->reply)($t);
        $first = ($this->row)($t);
        $this->travel(2)->hours();
        ($this->reply)($t, ['body' => 'A second answer from a colleague.'], $this->lead);

        $r = ($this->row)($t);
        expect($r->first_responded_at)->toBe($first->first_responded_at)->and($r->assignee_id)->toBe($this->agent->id)
            ->and(DB::table('notification_logs')->where('template_code', 'support.reply_received.email')->count())->toBe(2);   // each answer is announced, not only the first
    });

    it('lets staff leave a note for the team: nothing the customer can see or be told about changes', function () {
        $t = ($this->ticket)();

        ($this->reply)($t, ['body' => 'Courier says it is stuck at the hub.', 'internal' => '1'])->assertSessionHas('status');

        $r = ($this->row)($t);
        expect($r->status)->toBe('open')->and($r->first_responded_at)->toBeNull()->and($r->assignee_id)->toBeNull()->and($r->last_message_at)->toBe($t->last_message_at)->and(DB::table('support_messages')->where('is_internal', true)->count())->toBe(1);
        Mail::assertNothingSent();
        $this->actingAs($this->amina, 'web')->get("/en/account/support/{$t->public_id}")->assertDontSee('stuck at the hub');
        ($this->desk)()->get("/admin/support/{$t->public_id}")->assertSee('stuck at the hub')->assertSee('Team only');
    });

    it('needs the ticket to be opened first when it is closed, and refuses empty or unknown tickets', function () {
        $closed = ($this->ticket)($this->amina, ['status' => 'closed', 'closed_at' => now()]);

        ($this->reply)($closed)->assertSessionHasErrors('support');
        ($this->reply)(($this->ticket)(), ['body' => 'no'])->assertSessionHasErrors('body');
        ($this->desk)()->post('/admin/support/'.str_repeat('0', 26).'/reply', ['body' => 'Hello there friend'])->assertNotFound();
        expect(DB::table('support_messages')->where('ticket_id', $closed->id)->count())->toBe(1);
    });

    it('can send a picture with an answer, which the customer can open', function () {
        $t = ($this->ticket)();

        ($this->reply)($t, ['files' => [UploadedFile::fake()->image('map.png', 80, 80)]])->assertSessionHasNoErrors();

        $file = DB::table('support_attachments')->first();
        $this->actingAs($this->amina, 'web')->get("/en/account/support/files/{$file->public_id}")->assertOk();
        ($this->desk)($this->viewer)->get("/admin/support/files/{$file->public_id}")->assertOk();
        ($this->desk)($this->nobody)->get("/admin/support/files/{$file->public_id}")->assertForbidden();
    });
});

describe('looking after a ticket', function () {
    it('lets anyone who can answer take a ticket for themselves and let it go, but only a manager give it to someone else', function () {
        $t = ($this->ticket)();

        ($this->desk)()->post("/admin/support/{$t->public_id}/assign", ['assignee' => $this->agent->id])->assertSessionHas('status');
        expect(($this->row)($t)->assignee_id)->toBe($this->agent->id);
        ($this->desk)()->post("/admin/support/{$t->public_id}/assign", ['assignee' => $this->lead->id])->assertSessionHasErrors('support');
        expect(($this->row)($t)->assignee_id)->toBe($this->agent->id);
        ($this->desk)()->post("/admin/support/{$t->public_id}/assign", [])->assertSessionHas('status');   // lets go of their own
        expect(($this->row)($t)->assignee_id)->toBeNull();

        ($this->desk)($this->lead)->post("/admin/support/{$t->public_id}/assign", ['assignee' => $this->agent->id])->assertSessionHas('status');
        expect(($this->row)($t)->assignee_id)->toBe($this->agent->id);
        ($this->desk)()->post("/admin/support/{$t->public_id}/assign", ['assignee' => $this->lead->id])->assertSessionHasErrors('support');
        ($this->desk)($this->lead)->post("/admin/support/{$t->public_id}/assign", [])->assertSessionHas('status');
        expect(($this->row)($t)->assignee_id)->toBeNull()->and(DB::table('audit_events')->where('action', 'support.assigned')->count())->toBe(4);
    });

    it('will not give a ticket to someone who cannot answer, or to a customer', function () {
        $t = ($this->ticket)();

        ($this->desk)($this->lead)->post("/admin/support/{$t->public_id}/assign", ['assignee' => $this->viewer->id])->assertSessionHasErrors('support');
        ($this->desk)($this->lead)->post("/admin/support/{$t->public_id}/assign", ['assignee' => $this->amina->id])->assertSessionHasErrors('support');
        expect(($this->row)($t)->assignee_id)->toBeNull();
    });

    it('is closed and opened again by a manager, once each, and the database holds the allowed moves', function () {
        $t = ($this->ticket)();

        ($this->desk)($this->lead)->post("/admin/support/{$t->public_id}/close")->assertSessionHas('status');
        $this->travel(5)->minutes();   // a second press comes a little later, as a real one would
        ($this->desk)($this->lead)->post("/admin/support/{$t->public_id}/close")->assertSessionHasErrors('support');
        expect(($this->row)($t)->status)->toBe('closed')->and(DB::table('audit_events')->where('action', 'support.closed')->count())->toBe(1);
        expect(($this->row)($t)->status)->toBe('closed')->and(($this->row)($t)->closed_at)->not->toBeNull();
        ($this->desk)($this->lead)->post("/admin/support/{$t->public_id}/reopen")->assertSessionHas('status');
        ($this->desk)($this->lead)->post("/admin/support/{$t->public_id}/reopen")->assertSessionHasErrors('support');
        expect(($this->row)($t)->status)->toBe('open')->and(($this->row)($t)->closed_at)->toBeNull();

        $move = fn (string $to, array $more = []) => fn () => DB::table('support_tickets')->where('id', $t->id)->update(['status' => $to] + $more);
        expect($move('closed'))->toThrow(QueryException::class)                                    // closed needs its time
            ->and($move('answered'))->not->toThrow(QueryException::class)->and($move('closed', ['closed_at' => now()]))->not->toThrow(QueryException::class)
            ->and($move('answered', ['closed_at' => null]))->toThrow(QueryException::class);       // a closed ticket only opens again
    });
});

describe('ready-made answers', function () {
    it('are made by a manager in both languages, as plain text, with the English one required', function () {
        $save = fn (array $texts, array $more = []) => ($this->desk)($this->lead)->post('/admin/support/canned', ['texts' => $texts, 'sort' => 1, 'active' => '1'] + $more);

        $save(['en' => ['title' => 'Delay', 'body' => 'Dear {{ customer }}, sorry about order {{ order }}.'], 'bn' => ['title' => 'দেরি', 'body' => 'প্রিয় {{ customer }}, অর্ডার {{ order }} এর জন্য দুঃখিত।']])->assertRedirect('/admin/support/canned');
        $save(['en' => ['title' => 'Half', 'body' => '']])->assertSessionHasErrors('canned');
        $save(['bn' => ['title' => 'শুধু বাংলা', 'body' => 'লেখা']])->assertSessionHasErrors('canned');
        $save(['en' => ['title' => str_repeat('t', 81), 'body' => 'x']])->assertSessionHasErrors();

        expect(DB::table('canned_replies')->count())->toBe(1)->and(DB::table('canned_reply_translations')->count())->toBe(2);
        ($this->desk)($this->lead)->get('/admin/support/canned')->assertOk()->assertSee('Delay');
    });

    it('start an answer for a customer in their language with the name and order filled in, and nothing else is run', function () {
        $order = ($this->orderFor)($this->amina->id, [$this->tee['variantId'] => 1]);
        ($this->desk)($this->lead)->post('/admin/support/canned', ['active' => '1', 'texts' => ['en' => ['title' => 'Delay', 'body' => 'Dear {{ customer }}, about {{ order }} {{ 7*7 }} <b>x</b>.'], 'bn' => ['title' => 'দেরি', 'body' => 'প্রিয় {{ customer }}, {{ order }}।']]]);
        $id = DB::table('canned_replies')->value('public_id');
        $english = ($this->ticket)($this->amina, ['order_id' => $order->id]);
        $bangla = ($this->ticket)($this->bob, ['locale' => 'bn']);

        $html = ($this->desk)()->get("/admin/support/{$english->public_id}?canned={$id}")->assertOk()->assertSee('Dear Amina Rahman, about '.$order->order_number, false)->getContent();
        expect($html)->toContain('{{ 7*7 }}')->and($html)->toContain('&lt;b&gt;x&lt;/b&gt;')->and($html)->not->toContain('<b>x</b>');
        ($this->desk)()->get("/admin/support/{$bangla->public_id}?canned={$id}")->assertSee('প্রিয় Bob Karim', false);
        ($this->desk)()->get("/admin/support/{$english->public_id}?canned=".str_repeat('0', 26))->assertOk();
    });

    it('leaves out the ones not in use, can be edited, and can be deleted', function () {
        $make = fn (string $title, bool $active) => ($this->desk)($this->lead)->post('/admin/support/canned', ['texts' => ['en' => ['title' => $title, 'body' => 'Some text.']]] + ($active ? ['active' => '1'] : []));
        $make('In use', true);
        $make('Retired', false);
        $t = ($this->ticket)();

        ($this->desk)()->get("/admin/support/{$t->public_id}")->assertSee('In use')->assertDontSee('Retired');
        $retiredId = DB::table('canned_replies')->where('is_active', false)->value('public_id');
        ($this->desk)()->get("/admin/support/{$t->public_id}?canned={$retiredId}")->assertOk()->assertDontSee('Some text.');   // a retired reply cannot be used by its address either
        $id = DB::table('canned_replies')->where('is_active', true)->value('public_id');
        ($this->desk)($this->lead)->put("/admin/support/canned/{$id}", ['active' => '1', 'texts' => ['en' => ['title' => 'Renamed', 'body' => 'New words.']]])->assertRedirect('/admin/support/canned');
        ($this->desk)()->get("/admin/support/{$t->public_id}")->assertSee('Renamed');
        ($this->desk)($this->lead)->delete("/admin/support/canned/{$id}")->assertRedirect();
        expect(DB::table('canned_replies')->count())->toBe(1)->and(DB::table('canned_reply_translations')->count())->toBe(1);
        ($this->desk)($this->lead)->get('/admin/support/canned/'.str_repeat('0', 26).'/edit')->assertNotFound();
    });
});

describe('contact details and the first-answer time', function () {
    it('are set by a manager and shown to everyone on the contact page, in the visitor\'s language', function () {
        ($this->desk)($this->lead)->put('/admin/support/contact', ['phone' => '+880 1711-111111', 'email' => 'help@example.com', 'hours' => ['en' => '10am to 6pm, Saturday to Thursday', 'bn' => 'সকাল ১০টা থেকে সন্ধ্যা ৬টা'], 'response_hours' => '12'])->assertSessionHas('status');

        $this->get('/en/contact')->assertOk()->assertSee('+880 1711-111111')->assertSee('help@example.com')->assertSee('10am to 6pm')->assertSee('tel:+8801711111111', false);
        $this->get('/bn/contact')->assertSee('সকাল ১০টা থেকে সন্ধ্যা ৬টা')->assertSee('যোগাযোগ করুন');
        ($this->desk)($this->lead)->put('/admin/support/contact', ['phone' => '<script>', 'email' => 'not-an-email', 'response_hours' => '0'])->assertSessionHasErrors(['phone', 'email', 'response_hours']);
    });

    it('says so when nothing has been entered yet', function () {
        $this->get('/en/contact')->assertOk()->assertSee('Our contact details will be here soon.');
    });

    it('sets how long a new ticket may wait for its first answer', function () {
        ($this->desk)($this->lead)->put('/admin/support/contact', ['response_hours' => '6']);
        $this->actingAs($this->amina, 'web')->post('/en/account/support', ['subject' => 'A question', 'category' => 'other', 'body' => 'Can you help me with something?']);

        expect(abs(now()->addHours(6)->diffInSeconds(CarbonImmutable::parse((string) DB::table('support_tickets')->value('sla_due_at'), 'UTC'), false)))->toBeLessThan(5);
    });

    it('is checked every hour: one error line when tickets were not answered in time, and silence otherwise', function () {
        Log::spy();
        ($this->ticket)($this->amina, ['sla_due_at' => now()->subHour()]);
        ($this->ticket)($this->bob, ['sla_due_at' => now()->subHour(), 'first_responded_at' => now(), 'status' => 'answered']);
        ($this->ticket)($this->bob, ['sla_due_at' => now()->addHour()]);

        expect(app(CheckSupportHealth::class)->handle())->toBe(1);
        Log::shouldHaveReceived('error')->withArgs(fn ($m, $c = []) => $m === 'support.alert' && $c === ['overdue_first_responses' => 1])->once();

        DB::table('support_tickets')->update(['first_responded_at' => now()]);
        expect(app(CheckSupportHealth::class)->handle())->toBe(0);
        Artisan::call('schedule:list');
        expect(Artisan::output())->toMatch('~support:health~');
    });
});

describe('privacy', function () {
    it('puts the customer\'s conversations in their data copy, without the team\'s notes, and is part of every copy and erasure', function () {
        $t = ($this->ticket)();
        ($this->reply)($t, ['body' => 'We are looking into it today.']);
        ($this->reply)($t, ['body' => 'Hidden note about the courier', 'internal' => '1']);
        $other = ($this->ticket)($this->bob);

        $copy = app(SupportPersonalData::class)->export($this->amina->id);
        expect($copy)->toHaveCount(1)->and($copy[0]['reference'])->toBe($t->reference)->and($copy[0]['messages'])->toHaveCount(2)->and(json_encode($copy))->not->toContain('Hidden note')->and(json_encode($copy))->not->toContain($other->reference);
        expect(collect(app(PersonalDataCatalog::class)->exporters())->map(fn ($e) => $e::class)->all())->toContain(SupportPersonalData::class)->and(collect(app(PersonalDataCatalog::class)->erasers())->map(fn ($e) => $e::class)->all())->toContain(SupportPersonalData::class);
    });

    it('blocks erasure while a ticket is open or answered, and then removes the tickets, messages and files, only theirs', function () {
        $mine = ($this->ticket)();
        $theirs = ($this->ticket)($this->bob);
        $this->actingAs($this->amina, 'web')->post("/en/account/support/{$mine->public_id}/reply", ['body' => 'Here is a picture.', 'files' => [UploadedFile::fake()->image('p.png', 50, 50)]]);
        $this->actingAs($this->bob, 'web')->post("/en/account/support/{$theirs->public_id}/reply", ['body' => 'My picture too.', 'files' => [UploadedFile::fake()->image('q.png', 50, 50)]]);
        $personal = app(SupportPersonalData::class);
        expect($personal->blockers($this->amina->id))->toBe(['open']);
        DB::table('support_tickets')->where('id', $mine->id)->update(['status' => 'answered']);
        expect($personal->blockers($this->amina->id))->toBe(['open']);
        DB::table('support_tickets')->where('id', $mine->id)->update(['status' => 'closed', 'closed_at' => now()]);
        expect($personal->blockers($this->amina->id))->toBe([]);
        $myKey = DB::table('support_attachments as a')->join('support_messages as m', 'm.id', '=', 'a.message_id')->where('m.ticket_id', $mine->id)->value('a.storage_key');
        $theirKey = DB::table('support_attachments as a')->join('support_messages as m', 'm.id', '=', 'a.message_id')->where('m.ticket_id', $theirs->id)->value('a.storage_key');

        $personal->erase($this->amina->id);
        $personal->erase($this->amina->id);

        expect(DB::table('support_tickets')->where('user_id', $this->amina->id)->count())->toBe(0)->and(DB::table('support_messages')->where('ticket_id', $mine->id)->count())->toBe(0)->and(DB::table('support_tickets')->where('user_id', $this->bob->id)->count())->toBe(1);
        Storage::disk('private')->assertMissing($myKey);
        Storage::disk('private')->assertExists($theirKey);
    });
});

describe('words', function () {
    it('shows the desk in English and in Bangla with no missing words', function () {
        $t = ($this->ticket)();
        $this->lead->forceFill(['locale' => 'bn'])->save();
        ($this->desk)($this->lead)->post('/admin/support/canned', ['active' => '1', 'texts' => ['en' => ['title' => 'Delay', 'body' => 'Sorry.']]]);

        foreach (['/admin/support', "/admin/support/{$t->public_id}", '/admin/support/canned', '/admin/support/canned/create', '/admin/support/contact'] as $page) {
            expect(($this->desk)($this->lead)->get($page)->assertOk()->getContent())->not->toContain('engagement::');
        }
        ($this->desk)($this->lead)->get('/admin/support')->assertSee('সহায়তার টিকিট');
    });
});
