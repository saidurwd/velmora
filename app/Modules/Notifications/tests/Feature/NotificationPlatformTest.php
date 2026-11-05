<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Models\User;
use App\Modules\Notifications\Actions\CheckNotificationHealth;
use App\Modules\Notifications\Actions\DeliverDueNotifications;
use App\Modules\Notifications\Actions\DeliverNotification;
use App\Modules\Notifications\Actions\PurgeNotificationPayloads;
use App\Modules\Notifications\Actions\RetryDeadNotification;
use App\Modules\Notifications\Actions\SaveNotificationTemplate;
use App\Modules\Notifications\Contracts\NotificationSender;
use App\Modules\Notifications\Contracts\SmsGateway;
use App\Modules\Notifications\Contracts\TemplateRegistry;
use App\Modules\Notifications\DTOs\Recipient;
use App\Modules\Notifications\DTOs\SmsResult;
use App\Modules\Notifications\DTOs\TemplateDefinition;
use App\Modules\Notifications\Exceptions\NotificationException;
use App\Modules\Notifications\Mail\NotificationMail;
use App\Modules\Notifications\Services\TemplateRenderer;
use App\Modules\Notifications\Sms\FakeSmsGateway;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\PhoneNumber;
use App\Modules\Platform\Enums\ActorType;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(LanguageSeeder::class);
    config(['services.sms.driver' => 'fake']);
    $this->sms = app(FakeSmsGateway::class);
    $this->sms->fail = false;
    $this->travelTo(CarbonImmutable::parse('2026-12-01 06:00:00', 'UTC'));   // 12:00 in Dhaka
    $registry = app(TemplateRegistry::class);
    $registry->register(new TemplateDefinition('test.sms', 'sms', 'transactional', ['name', 'code'], ['en' => [null, 'Hi {{ name }}, your code is {{ code }}.'], 'bn' => [null, 'নমস্কার {{ name }}, আপনার কোড {{ code }}।']], ['name' => 'Rahim', 'code' => '123456']));
    $registry->register(new TemplateDefinition('test.email', 'email', 'transactional', ['name', 'order'], ['en' => ['Order {{ order }} received', "Hello {{ name }},\nwe got your order {{ order }}."]], ['name' => 'Rahim', 'order' => 'VM-1']));
    $registry->register(new TemplateDefinition('test.promo', 'sms', 'marketing', ['name'], ['en' => [null, 'Sale for you, {{ name }}!']], ['name' => 'Rahim']));
    $this->send = fn (string $code, Recipient $to, array $vars = [], string $key = 'k1') => app(NotificationSender::class)->send($code, $to, $vars + ['name' => 'Rahim', 'code' => '123456', 'order' => 'VM-1'], $key, 'order:abc');
    $this->phone = new Recipient(phone: '01712345678', email: 'rahim@example.com', locale: 'en');
    $this->log = fn (string $publicId) => DB::table('notification_logs')->where('public_id', $publicId)->first();
    $this->texts = fn () => array_column($this->sms->sent(), 'message');
    $this->user = User::factory()->create(['email' => 'u@example.com']);
});

describe('the words', function () {
    it('fill in only declared placeholders, as plain text, in one pass', function () {
        $r = new TemplateRenderer;

        expect($r->render('Hi {{ name }} / {{name}} / {{  name  }}! {{ unknown }}.', ['name' => 'Rahim']))->toBe('Hi Rahim / Rahim / Rahim! .')
            ->and($r->render('{{ a }}', ['a' => "line1\nline2\r\n\tx\x00"]))->toBe('line1 line2 x')
            ->and($r->render('{{ a }}', ['a' => str_repeat('ক', 500)]))->toBe(str_repeat('ক', 300))
            ->and($r->render('{{ a }} {{ b }}', ['a' => '{{ b }}', 'b' => 'SECRET']))->toBe('{{ b }} SECRET')   // a value is never read as a template
            ->and($r->render('{{ a }}', ['a' => ['x']]))->toBe('')->and($r->render('{{ a }}|{{ b }}', ['a' => true, 'b' => 12]))->toBe('1|12');
    });

    it('know which names a text uses, and notice braces that are not a placeholder', function () {
        $r = new TemplateRenderer;

        expect($r->names('{{ a }} {{ b }} {{a}}'))->toBe(['a', 'b'])->and($r->hasStrayBraces('{{ a }} fine'))->toBeFalse()
            ->and($r->hasStrayBraces('{{ a.b }}'))->toBeTrue()->and($r->hasStrayBraces('{{ $x }}'))->toBeTrue()->and($r->hasStrayBraces('oops }}'))->toBeTrue()->and($r->hasStrayBraces('{{'))->toBeTrue()
            ->and($r->singleLine("a\r\nb   c\n"))->toBe('a b c');
    });
});

describe('sending', function () {
    it('records the message, delivers it in the person\'s language, erases the address and values once sent, and keeps no text', function () {
        $id = ($this->send)('test.sms', new Recipient(phone: '01712345678', locale: 'bn'));
        $row = ($this->log)($id);

        expect(($this->texts)())->toBe(['নমস্কার Rahim, আপনার কোড 123456।'])
            ->and($row->status)->toBe('sent')->and($row->recipient_masked)->toBe('+88017****5678')->and($row->payload)->toBeNull()->and((int) $row->template_version)->toBe(1)->and((int) $row->attempts)->toBe(1)
            ->and($row->sent_at)->not->toBeNull()->and($row->related)->toBe('order:abc')->and($row->locale)->toBe('bn');
        $everything = json_encode(DB::table('notification_logs')->get()->all(), JSON_UNESCAPED_UNICODE);
        expect($everything)->not->toContain('01712345678')->not->toContain('123456')->not->toContain('Rahim');   // neither the number, the code, nor the words
    });

    it('holds the address and values encrypted while it waits', function () {
        $this->sms->fail = true;
        $id = ($this->send)('test.sms', $this->phone);
        $row = ($this->log)($id);

        expect($row->payload)->not->toBeNull()->and($row->payload)->not->toContain('01712345678')->not->toContain('Rahim');
    });

    it('sends an email with a one-line subject and plain-text words that the layout escapes', function () {
        Mail::fake();

        ($this->send)('test.email', $this->phone, ['name' => '<script>alert(1)</script> Rahim', 'order' => "VM-9\r\nBcc: evil@example.com"]);

        Mail::assertSent(NotificationMail::class, function (NotificationMail $m) {
            $html = $m->render();

            return $m->hasTo('rahim@example.com') && $m->subjectLine === 'Order VM-9 Bcc: evil@example.com received' && ! str_contains($html, '<script>') && str_contains($html, '&lt;script&gt;')
                && str_contains($html, '<br') && $m->locale === 'en';
        });
    });

    it('uses the person\'s language, English for a language we do not have, and English words when there are none in theirs', function () {
        ($this->send)('test.sms', new Recipient(phone: '01712345678', locale: 'fr'), key: 'a');
        ($this->send)('test.promo', new Recipient(phone: '01712345679', locale: 'bn', userId: $this->user->id), key: 'b');
        DB::table('notification_preferences')->insert(['user_id' => $this->user->id, 'channel' => 'sms', 'marketing_opt_in' => true, 'created_at' => now(), 'updated_at' => now()]);
        ($this->send)('test.promo', new Recipient(phone: '01712345679', locale: 'bn', userId: $this->user->id), key: 'c');

        expect(($this->texts)())->toBe(['Hi Rahim, your code is 123456.', 'Sale for you, Rahim!'])   // French → English; Bangla words missing → English
            ->and(DB::table('notification_logs')->where('idempotency_key', 'like', 'test.sms|a')->value('locale'))->toBe('en');
    });

    it('sends one message however often the same event is announced, and again for another event', function () {
        $first = ($this->send)('test.sms', $this->phone, key: 'event-1');
        $again = ($this->send)('test.sms', $this->phone, key: 'event-1');
        $other = ($this->send)('test.sms', $this->phone, key: 'event-2');

        expect($again)->toBe($first)->and($other)->not->toBe($first)->and(($this->texts)())->toHaveCount(2)->and(DB::table('notification_logs')->count())->toBe(2);
    });

    it('records why a message could not go, instead of failing silently', function () {
        $none = ($this->send)('test.sms', new Recipient(email: 'a@example.com'), key: 'a');
        $bad = ($this->send)('test.sms', new Recipient(phone: '12345'), key: 'b');
        $badMail = ($this->send)('test.email', new Recipient(email: 'not-an-email'), key: 'c');

        foreach ([$none, $bad, $badMail] as $id) {
            expect(($this->log)($id)->status)->toBe('skipped')->and(($this->log)($id)->skip_reason)->toBe('no_address')->and(($this->log)($id)->payload)->toBeNull();
        }
        expect(($this->texts)())->toBe([]);
    });

    it('refuses a message no module has declared', function () {
        expect(fn () => ($this->send)('nope.nothing', $this->phone))->toThrow(NotificationException::class, 'template_unknown');
    });

    it('does not send while the message is switched off', function () {
        app(SaveNotificationTemplate::class)->handle(Actor::system('t'), 'test.sms', 'en', null, 'Hi {{ name }}', false);

        $id = ($this->send)('test.sms', $this->phone);

        expect(($this->log)($id)->status)->toBe('skipped')->and(($this->log)($id)->skip_reason)->toBe('template_off')->and(($this->texts)())->toBe([]);
    });
});

describe('marketing and the person\'s choice', function () {
    it('needs the person\'s yes on that channel; their messages about their own order do not', function () {
        $promo = fn (string $key, ?int $userId) => ($this->send)('test.promo', new Recipient(phone: '01712345678', userId: $userId), key: $key);
        expect(($this->log)($promo('guest', null))->skip_reason)->toBe('no_consent')->and(($this->log)($promo('nopref', $this->user->id))->skip_reason)->toBe('no_consent');

        DB::table('notification_preferences')->insert(['user_id' => $this->user->id, 'channel' => 'email', 'marketing_opt_in' => true, 'created_at' => now(), 'updated_at' => now()]);
        expect(($this->log)($promo('wrong-channel', $this->user->id))->skip_reason)->toBe('no_consent');   // yes to email is not yes to texts

        DB::table('notification_preferences')->insert(['user_id' => $this->user->id, 'channel' => 'sms', 'marketing_opt_in' => true, 'created_at' => now(), 'updated_at' => now()]);
        expect(($this->log)($promo('yes', $this->user->id))->status)->toBe('sent');

        DB::table('notification_preferences')->where('channel', 'sms')->update(['marketing_opt_in' => false]);
        expect(($this->log)($promo('no-again', $this->user->id))->skip_reason)->toBe('no_consent');
        expect(($this->log)(($this->send)('test.sms', new Recipient(phone: '01712345678', userId: $this->user->id), key: 'order-sms'))->status)->toBe('sent');   // transactional ignores the opt-out
    });

    it('waits out quiet hours, including ones that cross midnight, and then goes', function () {
        DB::table('notification_preferences')->insert(['user_id' => $this->user->id, 'channel' => 'sms', 'marketing_opt_in' => true, 'quiet_from' => '22:00:00', 'quiet_to' => '08:00:00', 'created_at' => now(), 'updated_at' => now()]);
        $promo = fn (string $key) => ($this->send)('test.promo', new Recipient(phone: '01712345678', userId: $this->user->id), key: $key);

        expect(($this->log)($promo('noon'))->status)->toBe('sent');   // 12:00 Dhaka: not quiet

        $this->travelTo(CarbonImmutable::parse('2026-12-01 17:00:00', 'UTC'));   // 23:00 Dhaka
        $late = ($this->log)($promo('late'));
        expect($late->status)->toBe('queued')->and(CarbonImmutable::parse($late->next_attempt_at, 'UTC')->format('Y-m-d H:i'))->toBe('2026-12-02 02:00');   // 08:00 Dhaka tomorrow

        $this->travelTo(CarbonImmutable::parse('2026-12-02 01:00:00', 'UTC'));   // 07:00 Dhaka: still quiet
        expect(app(DeliverDueNotifications::class)->handle())->toBe(0);
        $this->travelTo(CarbonImmutable::parse('2026-12-02 02:01:00', 'UTC'));
        expect(app(DeliverDueNotifications::class)->handle())->toBe(1)->and(($this->log)($late->public_id)->status)->toBe('sent');

        $this->travelTo(CarbonImmutable::parse('2026-12-02 20:00:00', 'UTC'));   // 02:00 Dhaka the next night: the early hours of the same quiet period
        $early = ($this->log)($promo('early'));
        expect($early->status)->toBe('queued')->and(CarbonImmutable::parse($early->next_attempt_at, 'UTC')->format('Y-m-d H:i'))->toBe('2026-12-03 02:00');
    });
});

describe('when it fails', function () {
    it('tries again after a growing wait, gives up after the last, and says what went wrong without the address or the words', function () {
        $this->sms->fail = true;
        $id = ($this->send)('test.sms', $this->phone);
        $row = ($this->log)($id);
        expect($row->status)->toBe('failed')->and((int) $row->attempts)->toBe(1)->and($row->last_error)->toBe('fake failure')->and(CarbonImmutable::parse($row->next_attempt_at, 'UTC')->diffInMinutes(now(), true))->toBe(1.0);

        expect(app(DeliverDueNotifications::class)->handle())->toBe(0)->and((int) ($this->log)($id)->attempts)->toBe(1);   // not due yet

        foreach ([[1, 5], [2, 30], [3, 120], [4, 360]] as [$attempt, $nextWait]) {
            $this->travel(((int) config('notifications.backoff_minutes')[$attempt - 1]) + 1)->minutes();
            app(DeliverDueNotifications::class)->handle();
            $row = ($this->log)($id);
            expect($row->status)->toBe('failed')->and((int) $row->attempts)->toBe($attempt + 1)->and(CarbonImmutable::parse($row->next_attempt_at, 'UTC')->diffInMinutes(now(), true))->toBe((float) $nextWait);
        }

        $this->travel(361)->minutes();
        app(DeliverDueNotifications::class)->handle();
        $row = ($this->log)($id);
        expect($row->status)->toBe('dead')->and((int) $row->attempts)->toBe(6)->and($row->next_attempt_at)->toBeNull()->and($row->payload)->not->toBeNull()->and($row->last_error)->not->toContain('0171');
        $this->travel(1000)->minutes();
        expect(app(DeliverDueNotifications::class)->handle())->toBe(0)->and(($this->log)($id)->status)->toBe('dead')->and(($this->texts)())->toBe([]);
    });

    it('delivers on a later try once the cause is gone, and never twice', function () {
        $this->sms->fail = true;
        $id = ($this->send)('test.sms', $this->phone);
        $this->sms->fail = false;
        $this->travel(2)->minutes();

        app(DeliverDueNotifications::class)->handle();
        app(DeliverDueNotifications::class)->handle();

        expect(($this->log)($id)->status)->toBe('sent')->and((int) ($this->log)($id)->attempts)->toBe(2)->and(($this->texts)())->toHaveCount(1);
    });

    it('treats an email that cannot be sent the same way', function () {
        Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP down for rahim@example.com'));

        $id = ($this->send)('test.email', $this->phone);

        expect(($this->log)($id)->status)->toBe('failed')->and(($this->log)($id)->last_error)->toBe(RuntimeException::class);
    });

    it('is taken by the first worker for the whole time it is being sent: a second one that arrives meanwhile finds it taken', function () {
        $this->sms->fail = true;
        $id = ($this->send)('test.sms', $this->phone);
        $rowId = (int) ($this->log)($id)->id;
        $this->sms->fail = false;
        $this->travel(2)->minutes();
        $inner = new ArrayObject;
        $this->app->bind(SmsGateway::class, fn () => new class($rowId, $inner) implements SmsGateway
        {
            public function __construct(private int $rowId, private ArrayObject $inner) {}

            public function send(PhoneNumber $to, string $message, array $meta = []): SmsResult
            {
                $this->inner->append(app(DeliverNotification::class)->handle($this->rowId));   // another worker arrives while this one is still sending

                return new SmsResult(true, 'ref');
            }
        });

        $outer = app(DeliverNotification::class)->handle($rowId);

        expect($outer)->toBe('sent')->and($inner->getArrayCopy())->toBe(['busy'])->and((int) ($this->log)($id)->attempts)->toBe(2);
    });

    it('lets one worker take a message and a second find it taken, and takes over one abandoned for ten minutes', function () {
        $this->sms->fail = true;
        $id = ($this->send)('test.sms', $this->phone);
        $this->sms->fail = false;
        $rowId = (int) ($this->log)($id)->id;
        $this->travel(2)->minutes();
        DB::table('notification_logs')->where('id', $rowId)->update(['status' => 'sending', 'claimed_at' => now()]);   // a worker took it and has not finished

        expect(app(DeliverNotification::class)->handle($rowId))->toBe('busy');
        $this->travel(11)->minutes();
        expect(app(DeliverNotification::class)->handle($rowId))->toBe('sent')->and(app(DeliverNotification::class)->handle($rowId))->toBe('busy')->and(($this->texts)())->toHaveCount(1);
    });
});

describe('staff sending a dead message again', function () {
    beforeEach(function () {
        $this->staff = new Actor(ActorType::Staff, $this->user->id, null, 'Amina');
        $this->sms->fail = true;
        $this->dead = ($this->send)('test.sms', $this->phone);
        foreach (range(1, 6) as $_) {
            $this->travel(400)->minutes();
            app(DeliverDueNotifications::class)->handle();
        }
        $this->sms->fail = false;
    });

    it('needs a reason, works once, and is recorded', function () {
        expect(($this->log)($this->dead)->status)->toBe('dead');
        expect(fn () => app(RetryDeadNotification::class)->handle($this->staff, $this->dead, '  '))->toThrow(NotificationException::class, 'reason_required');

        app(RetryDeadNotification::class)->handle($this->staff, $this->dead, 'the provider was down');
        expect(($this->log)($this->dead)->status)->toBe('sent')->and(($this->texts)())->toHaveCount(1)->and(DB::table('audit_events')->where('action', 'notifications.retried')->count())->toBe(1);
        expect(fn () => app(RetryDeadNotification::class)->handle($this->staff, $this->dead, 'again'))->toThrow(NotificationException::class, 'not_dead');
        expect(fn () => app(RetryDeadNotification::class)->handle($this->staff, '01zzzzzzzzzzzzzzzzzzzzzzzz', 'x'))->toThrow(NotificationException::class, 'not_found');
    });

    it('cannot be done once the kept address has been erased after the retention period', function () {
        $this->travel(13)->days();
        expect(app(PurgeNotificationPayloads::class)->handle())->toBe(0);
        $this->travel(2)->days();
        expect(app(PurgeNotificationPayloads::class)->handle())->toBe(1)->and(($this->log)($this->dead)->payload)->toBeNull();

        expect(fn () => app(RetryDeadNotification::class)->handle($this->staff, $this->dead, 'late'))->toThrow(NotificationException::class, 'payload_expired');
        expect(($this->log)($this->dead)->status)->toBe('dead');
    });

    it('leaves the payloads of messages that are not dead alone', function () {
        $this->sms->fail = true;
        $failing = ($this->send)('test.sms', $this->phone, key: 'other');
        $this->travel(30)->days();

        app(PurgeNotificationPayloads::class)->handle();

        expect(($this->log)($failing)->payload)->not->toBeNull();
    });
});

describe('the staff\'s warning', function () {
    it('writes one error line when messages are dead, failing or overdue, and stays quiet when all is well', function () {
        Log::spy();
        expect(app(CheckNotificationHealth::class)->handle())->toBe(['dead' => 0, 'failing' => 0, 'overdue' => 0]);
        Log::shouldNotHaveReceived('error');

        $this->sms->fail = true;
        ($this->send)('test.sms', $this->phone);
        foreach (range(1, 7) as $_) {
            $this->travel(400)->minutes();
            app(DeliverDueNotifications::class)->handle();
        }

        expect(app(CheckNotificationHealth::class)->handle()['dead'])->toBe(1);
        Log::shouldHaveReceived('error')->withArgs(fn ($m, $c = []) => $m === 'notifications.alert' && ($c['dead'] ?? 0) === 1 && ! str_contains(json_encode($c), '0171'))->once();
    });
});

describe('the words staff write', function () {
    beforeEach(function () {
        $this->staff = new Actor(ActorType::Staff, $this->user->id, null, 'Amina');
        $this->save = fn (string $code, string $locale, ?string $subject, string $body, bool $active = true) => app(SaveNotificationTemplate::class)->handle($this->staff, $code, $locale, $subject, $body, $active);
    });

    it('become a new version that the next message uses, with the old words kept', function () {
        expect(($this->save)('test.sms', 'en', null, 'Dear {{ name }}: {{ code }}'))->toBe(2);
        ($this->send)('test.sms', $this->phone);

        expect(($this->texts)())->toBe(['Dear Rahim: 123456'])->and((int) DB::table('notification_logs')->value('template_version'))->toBe(2)
            ->and(DB::table('notification_template_versions')->where('locale', 'en')->orderBy('version')->pluck('body')->all())->toBe(['Hi {{ name }}, your code is {{ code }}.', 'Dear {{ name }}: {{ code }}'])
            ->and(DB::table('audit_events')->where('action', 'notifications.template_changed')->count())->toBe(1);
    });

    it('make no new version when the same words are saved again, and a language with no words yet gets its first', function () {
        ($this->save)('test.sms', 'en', null, 'Dear {{ name }}: {{ code }}');
        expect(($this->save)('test.sms', 'en', null, "Dear {{ name }}: {{ code }}  \r\n"))->toBe(2)   // only spaces and line ends differ
            ->and(($this->save)('test.promo', 'bn', null, 'ছাড় {{ name }}'))->toBe(1);
    });

    it('are refused when they use a variable the message does not have, stray braces, nothing, or too much', function () {
        $refuse = fn (callable $call, string $reason) => expect($call)->toThrow(NotificationException::class, $reason);

        $refuse(fn () => ($this->save)('test.sms', 'en', null, 'Hi {{ order }}'), 'variable_unknown');
        $refuse(fn () => ($this->save)('test.sms', 'en', null, 'Hi {{ name.first }}'), 'braces_invalid');
        $refuse(fn () => ($this->save)('test.sms', 'en', null, 'Hi {{ name }} {{ $code }}'), 'braces_invalid');
        $refuse(fn () => ($this->save)('test.sms', 'en', null, '   '), 'words_required');
        $refuse(fn () => ($this->save)('test.sms', 'en', null, str_repeat('a', 1001)), 'too_long');
        $refuse(fn () => ($this->save)('test.sms', 'xx', null, 'Hi'), 'locale_unknown');
        $refuse(fn () => ($this->save)('nope.nothing', 'en', null, 'Hi'), 'template_unknown');
        $refuse(fn () => ($this->save)('test.email', 'en', '', 'Hi'), 'subject_required');
        $refuse(fn () => ($this->save)('test.email', 'en', "Hello\nBcc: x@example.com", 'Hi'), 'subject_invalid');
        $refuse(fn () => ($this->save)('test.email', 'en', 'Order {{ code }}', 'Hi'), 'variable_unknown');   // the subject follows the same rule
        expect(DB::table('notification_template_versions')->count())->toBe(0);   // nothing was saved by any refusal
    });

    it('can never be changed or removed afterwards', function () {
        ($this->save)('test.sms', 'en', null, 'Dear {{ name }}');

        expect(fn () => DB::table('notification_template_versions')->update(['body' => 'x']))->toThrow(QueryException::class)
            ->and(fn () => DB::table('notification_template_versions')->delete())->toThrow(QueryException::class);
    });
});

describe('the database', function () {
    it('refuses a record with a status or channel it does not know, or the same event twice', function () {
        $row = ['public_id' => strtolower((string) Str::ulid()), 'idempotency_key' => 'x|1', 'template_code' => 'x', 'channel' => 'sms', 'kind' => 'transactional', 'locale' => 'en', 'recipient_masked' => 'm', 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()];
        DB::table('notification_logs')->insert($row);

        expect(fn () => DB::table('notification_logs')->insert(['public_id' => strtolower((string) Str::ulid())] + $row))->toThrow(QueryException::class);   // same key
        foreach ([['status' => 'maybe'], ['channel' => 'fax'], ['kind' => 'spam']] as $bad) {
            expect(fn () => DB::table('notification_logs')->insert(['public_id' => strtolower((string) Str::ulid()), 'idempotency_key' => 'x|'.json_encode($bad)] + $bad + $row))->toThrow(QueryException::class);
        }
        expect(fn () => DB::table('notification_preferences')->insert(['user_id' => User::factory()->create()->id, 'channel' => 'sms', 'quiet_from' => '22:00:00', 'created_at' => now(), 'updated_at' => now()]))->toThrow(QueryException::class);   // quiet hours need both ends
    });
});
