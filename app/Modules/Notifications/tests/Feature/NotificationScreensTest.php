<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Notifications\Actions\DeliverDueNotifications;
use App\Modules\Notifications\Actions\PurgeNotificationPayloads;
use App\Modules\Notifications\Contracts\NotificationSender;
use App\Modules\Notifications\Contracts\TemplateRegistry;
use App\Modules\Notifications\DTOs\Recipient;
use App\Modules\Notifications\DTOs\TemplateDefinition;
use App\Modules\Notifications\Sms\FakeSmsGateway;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed([LanguageSeeder::class, RolesAndPermissionsSeeder::class]);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    config(['services.sms.driver' => 'fake']);
    $this->sms = app(FakeSmsGateway::class);
    $this->sms->fail = false;
    $this->travelTo(CarbonImmutable::parse('2026-12-01 06:00:00', 'UTC'));
    $registry = app(TemplateRegistry::class);
    $registry->register(new TemplateDefinition('test.sms', 'sms', 'transactional', ['name', 'code'], ['en' => [null, 'Hi {{ name }}, your code is {{ code }}.']], ['name' => 'Rahim', 'code' => '123456']));
    $registry->register(new TemplateDefinition('test.email', 'email', 'transactional', ['name'], ['en' => ['Hello {{ name }}', 'Dear {{ name }},\nthanks.']], ['name' => 'Rahim']));
    $this->manager = catalogStaff(['notifications.view', 'notifications.manage']);
    $this->viewer = catalogStaff(['notifications.view']);
    $this->nobody = catalogStaff(['catalog.view']);
    $this->as = fn (User $u) => $this->actingAs($u, 'admin');
    $this->send = fn (string $key = 'k', string $phone = '01712345678') => app(NotificationSender::class)->send('test.sms', new Recipient(phone: $phone), ['name' => 'Rahim', 'code' => '123456'], $key, 'order:abc');
    $this->die = function (string $key = 'dead') {
        $this->sms->fail = true;
        $id = ($this->send)($key);
        foreach (range(1, 7) as $_) {
            $this->travel(400)->minutes();
            app(DeliverDueNotifications::class)->handle();
        }
        $this->sms->fail = false;

        return $id;
    };
});

describe('who may see them', function () {
    it('needs a staff sign-in and the right permission; only a manager can change or retry', function () {
        $dead = ($this->die)();
        $urls = ['/admin/notifications/templates', '/admin/notifications/templates/test.sms', '/admin/notifications/logs'];
        foreach ($urls as $url) {
            $this->get($url)->assertRedirect();
        }
        foreach ($urls as $url) {
            ($this->as)($this->nobody)->get($url)->assertForbidden();
            ($this->as)($this->viewer)->get($url)->assertOk();
        }
        ($this->as)($this->viewer)->put('/admin/notifications/templates/test.sms', ['locale' => 'en', 'body' => 'x'])->assertForbidden();
        ($this->as)($this->viewer)->put('/admin/notifications/templates/test.sms/preview', ['locale' => 'en', 'body' => 'x'])->assertForbidden();
        ($this->as)($this->viewer)->post("/admin/notifications/logs/{$dead}/retry", ['reason' => 'x'])->assertForbidden();
        ($this->as)($this->nobody)->post("/admin/notifications/logs/{$dead}/retry", ['reason' => 'x'])->assertForbidden();
        expect(DB::table('notification_logs')->where('public_id', $dead)->value('status'))->toBe('dead')
            ->and(DB::table('notification_template_versions as v')->join('notification_templates as t', 't.id', '=', 'v.template_id')->where('t.code', 'test.sms')->count())->toBe(1);   // nobody changed the words
    });
});

describe('the messages', function () {
    it('are listed with their channel and kind, and the page says so when messages are failing', function () {
        ($this->as)($this->viewer)->get('/admin/notifications/templates')->assertOk()->assertSee('test.sms')->assertSee('test.email')->assertSee('Text message')->assertSee('Email')->assertDontSee('gave up');
        ($this->die)();
        ($this->as)($this->viewer)->get('/admin/notifications/templates')->assertSee('1 message(s) gave up')->assertSee('See the delivery list');
    });

    it('show the variables, the words in each language and the earlier versions', function () {
        ($this->as)($this->manager)->put('/admin/notifications/templates/test.sms', ['locale' => 'en', 'body' => 'Dear {{ name }}: {{ code }}', 'active' => '1'])->assertRedirect('/admin/notifications/templates/test.sms');

        $page = ($this->as)($this->viewer)->get('/admin/notifications/templates/test.sms')->assertOk()->assertSee('{{ name }}', false)->assertSee('{{ code }}', false)->assertSee('example: Rahim')->assertSee('version 2')
            ->assertSee('Dear {{ name }}: {{ code }}')->assertSee('Hi {{ name }}, your code is {{ code }}.')->assertSee('no words yet (English is used)');
        expect($page->getContent())->toContain('EN')->toContain('BN');
    });

    it('can be changed by a manager; the change takes effect on the next message, and a refusal says why and saves nothing', function () {
        ($this->as)($this->manager)->put('/admin/notifications/templates/test.sms', ['locale' => 'en', 'body' => 'Dear {{ name }}: {{ code }}', 'active' => '1'])->assertSessionHas('status', 'Saved.');
        ($this->send)('after');
        expect(array_column($this->sms->sent(), 'message'))->toBe(['Dear Rahim: 123456']);

        $refuse = fn (array $over) => ($this->as)($this->manager)->put('/admin/notifications/templates/test.sms', $over + ['locale' => 'en', 'active' => '1'])->assertSessionHasErrors('template');
        $refuse(['body' => 'Hi {{ order }}']);
        $refuse(['body' => 'Hi {{ name.first }}']);
        $refuse(['body' => str_repeat('a', 1001)]);
        ($this->as)($this->manager)->put('/admin/notifications/templates/test.sms', ['locale' => 'en', 'body' => '', 'active' => '1'])->assertSessionHasErrors('body');
        expect(DB::table('notification_template_versions')->where('locale', 'en')->count())->toBe(2);
    });

    it('can be switched off, and then nothing is sent', function () {
        ($this->as)($this->manager)->put('/admin/notifications/templates/test.sms', ['locale' => 'en', 'body' => 'Hi {{ name }}', 'active' => '0'])->assertRedirect();

        ($this->send)('off');

        expect($this->sms->sent())->toBe([])->and(DB::table('notification_logs')->value('skip_reason'))->toBe('template_off');
        ($this->as)($this->viewer)->get('/admin/notifications/templates')->assertSee('Off');
    });

    it('are previewed with example values without saving anything, and the preview shows hostile words as plain text', function () {
        $page = ($this->as)($this->manager)->put('/admin/notifications/templates/test.email/preview', ['locale' => 'en', 'subject' => "Hello {{ name }}\nBcc: x@example.com", 'body' => "Dear {{ name }} <script>alert(1)</script>\nthanks"])->assertOk();

        $page->assertSee('Hello Rahim Bcc: x@example.com')->assertSee('Dear Rahim')->assertSee('Nothing has been saved.');
        expect($page->getContent())->not->toContain('<script>alert(1)</script>')->and(DB::table('notification_template_versions')->where('locale', 'en')->count())->toBe(1);

        ($this->as)($this->manager)->put('/admin/notifications/templates/test.sms/preview', ['locale' => 'en', 'body' => 'Hi {{ order }}'])->assertOk()->assertSee('which this message does not have');
        ($this->as)($this->viewer)->get('/admin/notifications/templates/nope.nothing')->assertNotFound();
    });
});

describe('the delivery record', function () {
    it('lists what was tried with masked recipients and no address, words or kept payload on the page', function () {
        ($this->send)('one');
        $dead = ($this->die)('two');

        $page = ($this->as)($this->viewer)->get('/admin/notifications/logs')->assertOk()->assertSee('test.sms')->assertSee('+88017****5678')->assertSee('order:abc')->assertSee('Sent')->assertSee('Gave up');
        expect($page->getContent())->not->toContain('01712345678')->not->toContain('123456')->not->toContain('Rahim');
        expect($dead)->not->toBeEmpty();
    });

    it('filters by state, message, channel and date', function () {
        ($this->send)('one');
        ($this->die)('two');
        $skipped = app(NotificationSender::class)->send('test.email', new Recipient(email: 'bad'), ['name' => 'X'], 'three');

        ($this->as)($this->viewer)->get('/admin/notifications/logs?status=dead')->assertSee('Gave up')->assertDontSee('No usable address');
        ($this->as)($this->viewer)->get('/admin/notifications/logs?status=skipped')->assertSee('No usable address')->assertDontSee('+88017****5678');   // the other states' rows are not listed
        ($this->as)($this->viewer)->get('/admin/notifications/logs?template=test.email')->assertSee('No usable address')->assertDontSee('+88017');
        ($this->as)($this->viewer)->get('/admin/notifications/logs?channel=sms&status=sent')->assertSee('+88017****5678');
        ($this->as)($this->viewer)->get('/admin/notifications/logs?from=2027-01-01')->assertSee('No messages match.');
        ($this->as)($this->viewer)->get('/admin/notifications/logs?to=2026-12-01')->assertSee('+88017****5678')->assertDontSee('No usable address');   // the skipped one was made days later
        ($this->as)($this->viewer)->get('/admin/notifications/logs?from=garbage&status=nonsense')->assertOk();
        expect($skipped)->not->toBeEmpty();
    });

    it('lets a manager send a message that gave up again, with a reason, and only if its kept address is still there', function () {
        $dead = ($this->die)();

        ($this->as)($this->viewer)->get('/admin/notifications/logs')->assertDontSee('Send again');
        ($this->as)($this->manager)->get('/admin/notifications/logs')->assertSee('Send again');
        ($this->as)($this->manager)->post("/admin/notifications/logs/{$dead}/retry", ['reason' => ''])->assertSessionHasErrors('reason');
        ($this->as)($this->manager)->post("/admin/notifications/logs/{$dead}/retry", ['reason' => 'provider was down'])->assertRedirect('/admin/notifications/logs?status=dead')->assertSessionHas('status');
        expect(DB::table('notification_logs')->where('public_id', $dead)->value('status'))->toBe('sent')->and($this->sms->sent())->toHaveCount(1);
        ($this->as)($this->manager)->post("/admin/notifications/logs/{$dead}/retry", ['reason' => 'again'])->assertSessionHasErrors('log');

        $old = ($this->die)('old');
        $this->travel(15)->days();
        app(PurgeNotificationPayloads::class)->handle();
        ($this->as)($this->manager)->get('/admin/notifications/logs')->assertSee('The address was erased')->assertDontSee('Send again');
        ($this->as)($this->manager)->post("/admin/notifications/logs/{$old}/retry", ['reason' => 'late'])->assertSessionHasErrors('log');
    });

    it('does not ask more questions for more messages', function () {
        ($this->send)('a');
        ($this->as)($this->viewer)->get('/admin/notifications/logs')->assertOk();
        DB::enableQueryLog();
        ($this->as)($this->viewer)->get('/admin/notifications/logs')->assertOk();
        $few = count(DB::getQueryLog());
        foreach (range(1, 20) as $i) {
            ($this->send)("m{$i}", '0171234567'.($i % 10));
        }
        DB::flushQueryLog();
        ($this->as)($this->viewer)->get('/admin/notifications/logs')->assertOk();

        expect(count(DB::getQueryLog()))->toBe($few);
    });
});

describe('in Bangla', function () {
    it('reads the screens in Bangla', function () {
        $this->manager->forceFill(['locale' => 'bn'])->save();
        ($this->send)('a');

        ($this->as)($this->manager)->get('/admin/notifications/templates')->assertOk()->assertSee('দোকান যেসব বার্তা পাঠায়');
        ($this->as)($this->manager)->get('/admin/notifications/templates/test.sms')->assertOk()->assertSee('লেখায় যা ব্যবহার করা যায়')->assertSee('নতুন সংস্করণ হিসেবে সংরক্ষণ');
        ($this->as)($this->manager)->get('/admin/notifications/logs')->assertOk()->assertSee('বার্তা পাঠানোর তালিকা')->assertSee('পাঠানো হয়েছে');
        ($this->as)($this->manager)->put('/admin/notifications/templates/test.sms', ['locale' => 'en', 'body' => 'Hi {{ order }}', 'active' => '1'])->assertSessionHasErrors('template');
        expect(session('errors')->first('template'))->toContain('লেখায়');
    });
});
