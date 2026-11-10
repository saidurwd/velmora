<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Payments\Services\GatewayRegistry;
use App\Modules\Platform\Actions\SaveIntegration;
use App\Modules\Platform\Actions\TestIntegration;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Exceptions\SettingsException;
use App\Modules\Platform\Support\HostGuard;
use App\Modules\Platform\Support\IntegrationCredentials;
use App\Modules\Platform\Support\Integrations\MailHandshakeTest;
use App\Modules\Platform\Support\Settings;
use App\Modules\Platform\Support\SmtpHandshake;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\PermissionRegistrar;

/*
 * Phase 10, slice 4c: provider credentials saved by staff (SSLCommerz, SMS, SMTP), their connection tests, and how the rest of the app picks them up.
 * Nothing here reaches a real provider: HTTP is faked and the mail handshake is replaced.
 */

const INT_SECRET = 'Sup3rSecretStorePass-9876';

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, LanguageSeeder::class]);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    RateLimiter::clear('reauth:1');
    $this->password = 'Tr0ub4dor-and-3-horses';
    $this->manager = User::factory()->staff()->create(['password' => $this->password]);
    $this->manager->givePermissionTo('integrations.manage');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    config(['payments.sslcommerz.store_id' => null, 'payments.sslcommerz.store_password' => null, 'payments.sslcommerz.sandbox' => true, 'payments.allow_live' => false]);

    $this->version = fn (string $p) => app(IntegrationCredentials::class)->version($p);
    $this->save = fn (string $provider, array $f, array $over = []) => $this->actingAs($this->manager, 'admin')->put('/admin/settings/integrations/'.$provider, array_replace(['version' => ($this->version)($provider), 'password' => $this->password, 'f' => $f], $over));
    $this->audits = fn (string $action) => DB::table('audit_events')->where('action', $action)->orderBy('id')->get();
    $this->creds = fn () => app(IntegrationCredentials::class);
});

describe('who may see and change it', function () {
    it('is closed to guests and to staff without the right', function () {
        $this->get('/admin/settings/integrations')->assertRedirect();

        $other = User::factory()->staff()->create();
        $other->givePermissionTo('settings.view', 'settings.manage');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($other, 'admin')->get('/admin/settings/integrations')->assertForbidden();
        $this->actingAs($other, 'admin')->put('/admin/settings/integrations/sms', ['version' => 'x', 'password' => 'x'])->assertForbidden();
        $this->actingAs($other, 'admin')->post('/admin/settings/integrations/sms/test')->assertForbidden();
    });

    it('opens for the right and does not know other providers', function () {
        $this->actingAs($this->manager, 'admin')->get('/admin/settings/integrations')->assertOk()->assertSee('SSLCommerz')->assertSee('Store password');
        $this->actingAs($this->manager, 'admin')->put('/admin/settings/integrations/paypal', ['version' => 'x', 'password' => 'x'])->assertClientError();
        $this->actingAs($this->manager, 'admin')->post('/admin/settings/integrations/paypal/test')->assertClientError();
    });

    it('renders in Bangla', function () {
        $this->actingAs($this->manager, 'admin')->withSession(['locale' => 'bn'])->get('/admin/settings/integrations')->assertOk();
        app()->setLocale('bn');
        expect(__('platform::integrations.title'))->toBe('ইন্টিগ্রেশন');
    });
});

describe('saving', function () {
    it('keeps a secret encrypted, never shows it again and never audits it', function () {
        ($this->save)('sslcommerz', ['store_id' => 'velmora001@ssl', 'store_password' => INT_SECRET])->assertRedirect('/admin/settings/integrations')->assertSessionHas('status');

        $row = DB::table('settings')->where('scope', 'integration')->where('key', 'sslcommerz.store_password')->first();
        expect((bool) $row->is_secret)->toBeTrue()->and($row->value)->not->toContain(INT_SECRET)
            ->and(DB::table('settings')->where('scope', 'integration')->where('key', 'sslcommerz.store_id')->value('is_secret'))->toEqual(0)
            ->and(($this->creds)()->get('sslcommerz', 'store_password'))->toBe(INT_SECRET);

        $page = $this->actingAs($this->manager, 'admin')->get('/admin/settings/integrations')->assertOk()->getContent();
        expect($page)->not->toContain(INT_SECRET)->toContain('velmora001@ssl')->toContain('••••••••');

        $audit = json_encode(DB::table('audit_events')->get(), JSON_THROW_ON_ERROR);
        expect($audit)->not->toContain(INT_SECRET)->not->toContain(substr(INT_SECRET, -4))
            ->and(($this->audits)('integrations.changed'))->toHaveCount(1)->and(($this->audits)('integrations.changed')[0]->actor_id)->toBe($this->manager->id);
    });

    it('changes nothing when the password is wrong', function () {
        ($this->save)('sms', ['api_token' => 'tok123', 'sid' => 'VELMORA'], ['password' => 'not-the-password'])->assertSessionHasErrors('integration');

        expect(DB::table('settings')->where('scope', 'integration')->count())->toBe(0)->and(($this->audits)('integrations.changed'))->toHaveCount(0);
    });

    it('leaves a secret alone when its box is left blank, and takes it back to the server when asked', function () {
        ($this->save)('sms', ['api_token' => 'tok123', 'sid' => 'VELMORA'])->assertSessionHas('status');
        ($this->save)('sms', ['api_token' => '', 'sid' => 'NEWSID'])->assertSessionHas('status');
        expect(($this->creds)()->get('sms', 'api_token'))->toBe('tok123')->and(($this->creds)()->get('sms', 'sid'))->toBe('NEWSID');

        config(['services.sms.sslwireless.api_token' => 'fromserver']);
        ($this->save)('sms', ['api_token' => ''], ['remove' => ['api_token' => '1']])->assertSessionHas('status');
        expect(($this->creds)()->get('sms', 'api_token'))->toBe('fromserver')->and(($this->creds)()->source('sms', 'api_token'))->toBe('server');
        expect(json_encode(($this->audits)('integrations.changed')->last()->after_values ?? '', JSON_THROW_ON_ERROR))->not->toContain('tok123');
    });

    it('does not copy a server value into the database when it is saved unchanged', function () {
        config(['services.sms.sslwireless.sid' => 'SERVERSID']);
        ($this->save)('sms', ['sid' => 'SERVERSID', 'api_token' => ''])->assertSessionHas('status', __('platform::integrations.nothing_changed'));

        expect(DB::table('settings')->where('scope', 'integration')->count())->toBe(0)->and(($this->audits)('integrations.changed'))->toHaveCount(0);
    });

    it('turns a blanked ordinary field back to the server value', function () {
        config(['services.sms.sslwireless.sid' => 'SERVERSID']);
        ($this->save)('sms', ['sid' => 'MINE'])->assertSessionHas('status');
        expect(($this->creds)()->get('sms', 'sid'))->toBe('MINE');

        ($this->save)('sms', ['sid' => ''])->assertSessionHas('status');
        expect(($this->creds)()->get('sms', 'sid'))->toBe('SERVERSID');
    });

    it('refuses a save from an out-of-date form', function () {
        $stale = ($this->version)('sms');
        ($this->save)('sms', ['api_token' => 'first', 'sid' => 'A'])->assertSessionHas('status');
        ($this->save)('sms', ['api_token' => 'second', 'sid' => 'B'], ['version' => $stale])->assertSessionHasErrors();

        expect(($this->creds)()->get('sms', 'api_token'))->toBe('first');
    });

    it('checks every value', function (string $provider, array $f, string $field) {
        ($this->save)($provider, $f)->assertSessionHasErrors('f.'.$field);
        expect(DB::table('settings')->where('scope', 'integration')->count())->toBe(0);
    })->with([
        'token with symbols' => ['sms', ['api_token' => 'bad token!'], 'api_token'],
        'sender id too long' => ['sms', ['sid' => str_repeat('A', 21)], 'sid'],
        'store id with spaces' => ['sslcommerz', ['store_id' => 'has space'], 'store_id'],
        'store id too short' => ['sslcommerz', ['store_id' => 'ab'], 'store_id'],
        'mail port not allowed' => ['mail', ['port' => '8080'], 'port'],
        'mail port not a number' => ['mail', ['port' => '25x'], 'port'],
        'mail scheme' => ['mail', ['scheme' => 'http'], 'scheme'],
        'mail from not an address' => ['mail', ['from_address' => 'nope'], 'from_address'],
        'mail host with a path' => ['mail', ['host' => 'smtp.example.com/x'], 'host'],
    ]);

    it('reads Bangla digits in the mail port', function () {
        ($this->save)('mail', ['port' => '৫৮৭'])->assertSessionHas('status');

        expect(($this->creds)()->get('mail', 'port'))->toBe('587');
    });

    it('lowers the from address and stores only the fields that changed', function () {
        ($this->save)('mail', ['host' => 'smtp.example.com', 'from_address' => 'Shop@Example.COM'])->assertSessionHas('status');

        expect(($this->creds)()->get('mail', 'from_address'))->toBe('shop@example.com')->and(DB::table('settings')->where('scope', 'integration')->count())->toBe(2);
    });

    it('limits how often it can be tried', function () {
        for ($i = 0; $i < 10; $i++) {
            ($this->save)('sms', ['sid' => 'A'.$i], ['password' => 'wrong'])->assertSessionHasErrors();
        }
        RateLimiter::clear('reauth:'.$this->manager->id);
        ($this->save)('sms', ['sid' => 'Z'])->assertStatus(429);
    });
});

describe('a secret that cannot be read', function () {
    it('falls back to the server value and says to enter it again', function () {
        config(['payments.sslcommerz.store_password' => 'serverpass']);
        DB::table('settings')->insert(['scope' => 'integration', 'key' => 'sslcommerz.store_password', 'value' => json_encode('garbage-not-encrypted'), 'is_secret' => 1, 'created_at' => now(), 'updated_at' => now()]);
        Cache::forget('settings:integration:sslcommerz.store_password');

        expect(($this->creds)()->get('sslcommerz', 'store_password'))->toBe('serverpass')->and(($this->creds)()->source('sslcommerz', 'store_password'))->toBe('unreadable');
        $this->actingAs($this->manager, 'admin')->get('/admin/settings/integrations')->assertOk()->assertSee('cannot be read');
    });
});

describe('payments pick the saved credentials up', function () {
    it('is not available with nothing set, and is once staff save the credentials', function () {
        expect(app(GatewayRegistry::class)->has('sslcommerz'))->toBeFalse();

        ($this->save)('sslcommerz', ['store_id' => 'velmora001@ssl', 'store_password' => INT_SECRET])->assertSessionHas('status');
        $this->travel(2)->minutes();   // an answer is kept for a minute

        expect(app(GatewayRegistry::class)->has('sslcommerz'))->toBeTrue()->and(app(GatewayRegistry::class)->providers())->toContain('sslcommerz');
    });

    it('still works from the server settings alone, as before', function () {
        config(['payments.sslcommerz.store_id' => 'envshop@ssl', 'payments.sslcommerz.store_password' => 'envpass']);

        expect(app(GatewayRegistry::class)->has('sslcommerz'))->toBeTrue();
    });

    it('never opens live mode just because credentials were saved here', function () {
        config(['payments.sslcommerz.sandbox' => false, 'payments.allow_live' => true]);   // not production: the live rule still says no
        ($this->save)('sslcommerz', ['store_id' => 'livestore@ssl', 'store_password' => INT_SECRET])->assertSessionHas('status');
        $this->travel(2)->minutes();

        expect(app(GatewayRegistry::class)->has('sslcommerz'))->toBeFalse();
    });

    it('stops offering the provider when the saved credentials are removed', function () {
        ($this->save)('sslcommerz', ['store_id' => 'velmora001@ssl', 'store_password' => INT_SECRET]);
        $this->travel(2)->minutes();
        expect(app(GatewayRegistry::class)->has('sslcommerz'))->toBeTrue();

        ($this->save)('sslcommerz', ['store_id' => ''], ['remove' => ['store_password' => '1']])->assertSessionHas('status');
        $this->travel(2)->minutes();

        expect(app(GatewayRegistry::class)->has('sslcommerz'))->toBeFalse();
    });
});

describe('the connection tests', function () {
    $fixture = fn (string $name) => json_decode((string) file_get_contents(__DIR__.'/../../../Payments/tests/Fixtures/sslcommerz/'.$name.'.json'), true, flags: JSON_THROW_ON_ERROR);

    it('asks the sandbox about a made-up transaction, with nothing that moves money', function () {
        Http::fake(['*' => Http::response(['APIConnect' => 'DONE', 'no_of_trans_found' => 0, 'element' => []])]);
        ($this->save)('sslcommerz', ['store_id' => 'velmora001@ssl', 'store_password' => INT_SECRET]);

        $this->actingAs($this->manager, 'admin')->post('/admin/settings/integrations/sslcommerz/test')->assertRedirect('/admin/settings/integrations')->assertSessionHas('test_ok', __('platform::integrations.tests.sslcommerz_accepted'));

        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'sandbox') && str_contains($r->url(), 'merchantTransIDvalidationAPI') && str_starts_with((string) ($r->data()['tran_id'] ?? ''), 'conn-test-') && ! isset($r->data()['refund_amount']) && ! isset($r->data()['bank_tran_id']));
        expect(($this->audits)('integrations.tested'))->toHaveCount(1)->and(json_encode(DB::table('audit_events')->get(), JSON_THROW_ON_ERROR))->not->toContain(INT_SECRET);
    });

    it('says which way SSLCommerz refused', function (string $connect, string $code) {
        Http::fake(['*' => Http::response(['APIConnect' => $connect])]);
        ($this->save)('sslcommerz', ['store_id' => 'velmora001@ssl', 'store_password' => INT_SECRET]);

        $this->actingAs($this->manager, 'admin')->post('/admin/settings/integrations/sslcommerz/test')->assertSessionHas('test_bad', __('platform::integrations.tests.sslcommerz_'.$code));
    })->with([['FAILED', 'rejected'], ['INACTIVE', 'inactive'], ['INVALID_REQUEST', 'invalid'], ['SOMETHING', 'unreachable']]);

    it('says unreachable when the network fails, and not set when there is nothing to test', function () {
        $this->actingAs($this->manager, 'admin')->post('/admin/settings/integrations/sslcommerz/test')->assertSessionHas('test_bad', __('platform::integrations.tests.sslcommerz_not_set'));

        Http::fake(['*' => Http::response('', 500)]);
        ($this->save)('sslcommerz', ['store_id' => 'velmora001@ssl', 'store_password' => INT_SECRET]);
        $this->actingAs($this->manager, 'admin')->post('/admin/settings/integrations/sslcommerz/test')->assertSessionHas('test_bad', __('platform::integrations.tests.sslcommerz_unreachable'));
    });

    it('is not run against the real bank when the server is in live mode', function () {
        Http::fake();
        config(['payments.sslcommerz.sandbox' => false]);
        ($this->save)('sslcommerz', ['store_id' => 'velmora001@ssl', 'store_password' => INT_SECRET]);

        $this->actingAs($this->manager, 'admin')->post('/admin/settings/integrations/sslcommerz/test')->assertSessionHas('test_bad', __('platform::integrations.tests.sslcommerz_live_untouched'));
        Http::assertNothingSent();
    });

    it('checks the SMS format and sends nothing', function () {
        Http::fake();
        $this->actingAs($this->manager, 'admin')->post('/admin/settings/integrations/sms/test')->assertSessionHas('test_bad', __('platform::integrations.tests.sms_not_set'));

        ($this->save)('sms', ['api_token' => 'tok123', 'sid' => 'VELMORA']);
        $this->actingAs($this->manager, 'admin')->post('/admin/settings/integrations/sms/test')->assertSessionHas('test_ok', __('platform::integrations.tests.sms_format_ok'));

        config(['services.sms.sslwireless.api_token' => 'bad token!']);   // a server value that does not fit
        DB::table('settings')->where('scope', 'integration')->where('key', 'sms.api_token')->delete();
        app(Settings::class)->forget('sms.api_token', 'integration');
        $this->actingAs($this->manager, 'admin')->post('/admin/settings/integrations/sms/test')->assertSessionHas('test_bad', __('platform::integrations.tests.sms_format_bad'));
        Http::assertNothingSent();
    });

    it('signs in to the mail server and sends nothing, reporting only that it worked or not', function () {
        $calls = [];
        $this->app->instance(SmtpHandshake::class, new class($calls) extends SmtpHandshake
        {
            public function __construct(public array &$calls) {}

            public function check(string $host, int $port, bool $tls, ?string $username, ?string $password): void
            {
                $this->calls[] = [$host, $port, $tls, $username, $password];
                if ($password === 'wrong') {
                    throw new RuntimeException('535 5.7.8 bad credentials for secretuser');
                }
            }
        });
        config(['mail.mailers.smtp.host' => null]);
        $this->actingAs($this->manager, 'admin')->post('/admin/settings/integrations/mail/test')->assertSessionHas('test_bad', __('platform::integrations.tests.mail_not_set'));

        ($this->save)('mail', ['host' => 'smtp.example.com', 'port' => '465', 'scheme' => 'smtps', 'username' => 'secretuser', 'password' => 'good']);
        $this->actingAs($this->manager, 'admin')->post('/admin/settings/integrations/mail/test')->assertSessionHas('test_ok', __('platform::integrations.tests.mail_ok'));
        expect($calls)->toBe([['smtp.example.com', 465, true, 'secretuser', 'good']]);

        ($this->save)('mail', ['host' => 'smtp.example.com', 'port' => '465', 'scheme' => 'smtps', 'username' => 'secretuser', 'password' => 'wrong']);   // the form sends every field
        $this->actingAs($this->manager, 'admin')->post('/admin/settings/integrations/mail/test')->assertSessionHas('test_bad', __('platform::integrations.tests.mail_failed'));
        expect(json_encode(DB::table('audit_events')->get(), JSON_THROW_ON_ERROR))->not->toContain('bad credentials')->not->toContain('good')->not->toContain('wrong');   // the account name is an ordinary field and is recorded; the password and the server's words never are
    });
});

describe('the host guard', function () {
    it('refuses internal names and addresses outside development', function (string $host) {
        $this->app['env'] = 'production';

        expect(HostGuard::nameAllowed($host))->toBeFalse();
    })->with(['localhost', 'db.local', 'redis.internal', '127.0.0.1', '10.0.0.5', '192.168.1.10', '169.254.169.254', '::1']);

    it('allows an ordinary name, and everything on a development machine', function () {
        $this->app['env'] = 'production';
        expect(HostGuard::nameAllowed('smtp.example.com'))->toBeTrue();

        $this->app['env'] = 'local';
        expect(HostGuard::nameAllowed('localhost'))->toBeTrue();
    });

    it('refuses to save an internal host in production', function () {
        $this->app['env'] = 'production';
        $save = fn () => app(SaveIntegration::class)->handle(new Actor(ActorType::Staff, $this->manager->id, $this->manager->public_id, 'm'), 'mail', ['host' => '169.254.169.254'], [], ($this->version)('mail'), $this->password);

        expect($save)->toThrow(fn (SettingsException $e) => $e->reason === 'host_blocked' && $e->field === 'host');
        expect(DB::table('settings')->where('scope', 'integration')->count())->toBe(0);
    });
});

describe('the mail settings reach the mailer', function () {
    it('applies saved values to the mail configuration', function () {
        ($this->save)('mail', ['host' => 'smtp.saved.example', 'port' => '587', 'from_address' => 'hello@saved.example', 'from_name' => 'Saved Shop', 'password' => 'p4ss']);
        config(['mail.mailers.smtp.host' => 'server.example', 'mail.from.address' => 'server@example.com']);

        app(IntegrationCredentials::class)->applyMail();

        expect(config('mail.mailers.smtp.host'))->toBe('smtp.saved.example')->and(config('mail.mailers.smtp.port'))->toBe(587)
            ->and(config('mail.from.address'))->toBe('hello@saved.example')->and(config('mail.from.name'))->toBe('Saved Shop')->and(config('mail.mailers.smtp.password'))->toBe('p4ss');
    });

    it('leaves the server settings where nothing is saved', function () {
        config(['mail.mailers.smtp.host' => 'server.example']);
        app(IntegrationCredentials::class)->applyMail();

        expect(config('mail.mailers.smtp.host'))->toBe('server.example');
    });
});

describe('what the first run of mutations showed was not yet covered', function () {
    it('refuses an unknown provider even when called past the route', function () {
        $actor = new Actor(ActorType::Staff, $this->manager->id, $this->manager->public_id, 'm');

        expect(fn () => app(SaveIntegration::class)->handle($actor, 'paypal', [], [], 'x', $this->password))->toThrow(SettingsException::class)
            ->and(fn () => app(TestIntegration::class)->handle($actor, 'paypal'))->toThrow(SettingsException::class);
        expect(($this->audits)('integrations.tested'))->toHaveCount(0);
    });

    it('limits the length of every text and checks the sender id characters', function () {
        ($this->save)('mail', ['from_name' => str_repeat('x', 300)])->assertSessionHasErrors('f.from_name');
        ($this->save)('sms', ['sid' => 'bad id!'])->assertSessionHasErrors('f.sid');
        expect(DB::table('settings')->where('scope', 'integration')->count())->toBe(0);
    });

    it('records a secret only as "changed" and never any part of it', function () {
        ($this->save)('sslcommerz', ['store_id' => 'velmora001@ssl', 'store_password' => INT_SECRET])->assertSessionHas('status');
        $row = ($this->audits)('integrations.changed')[0];
        $fields = json_decode((string) $row->after, true)['fields'];

        expect($fields['store_id'])->toBe(['before' => null, 'after' => 'velmora001@ssl'])->and(json_encode($fields['store_password']))->not->toContain(INT_SECRET)->and(array_keys($fields))->toBe(['store_id', 'store_password']);
        expect(json_encode($fields['store_password']))->toMatch('/changed|redacted|\*/i');
    });

    it('shows no part of a short secret', function () {
        ($this->save)('sms', ['api_token' => 'abcd1234', 'sid' => 'X']);
        expect(($this->creds)()->display('sms', 'api_token'))->toBe('••••••••');

        ($this->save)('sms', ['api_token' => 'abcdefgh9876', 'sid' => 'X']);
        expect(($this->creds)()->display('sms', 'api_token'))->toBe('••••••••9876');
    });

    it('does not test a mail server on a port or host that may not be used', function () {
        $called = false;
        $this->app->instance(SmtpHandshake::class, new class($called) extends SmtpHandshake
        {
            public function __construct(public bool &$called) {}

            public function check(string $host, int $port, bool $tls, ?string $username, ?string $password): void
            {
                $this->called = true;
            }
        });
        config(['mail.mailers.smtp.host' => 'smtp.example.com', 'mail.mailers.smtp.port' => 8080]);   // as if set by the server
        $this->actingAs($this->manager, 'admin')->post('/admin/settings/integrations/mail/test')->assertSessionHas('test_bad', __('platform::integrations.tests.mail_host_blocked'));

        $this->app['env'] = 'production';
        config(['mail.mailers.smtp.host' => '10.0.0.5', 'mail.mailers.smtp.port' => 587]);
        expect(app(MailHandshakeTest::class)->run()->code)->toBe('host_blocked')->and($called)->toBeFalse();
    });

    it('logs only the kind of mail failure', function () {
        $logged = [];
        Event::listen(MessageLogged::class, function ($e) use (&$logged) {
            $logged[] = $e->message.json_encode($e->context);
        });
        $this->app->instance(SmtpHandshake::class, new class extends SmtpHandshake
        {
            public function check(string $host, int $port, bool $tls, ?string $username, ?string $password): void
            {
                throw new RuntimeException('535 bad credentials for secretuser');
            }
        });
        config(['mail.mailers.smtp.host' => 'smtp.example.com', 'mail.mailers.smtp.port' => 587]);
        $this->actingAs($this->manager, 'admin')->post('/admin/settings/integrations/mail/test')->assertSessionHas('test_bad');

        expect(implode(' ', $logged))->toContain('integrations.mail_test_failed')->toContain('RuntimeException')->not->toContain('bad credentials');
    });

    it('does not send secrets or the password back to the form after a failed save', function () {
        ($this->save)('sslcommerz', ['store_id' => 'ab', 'store_password' => INT_SECRET])->assertSessionHasErrors('f.store_id');

        $old = session()->getOldInput();
        expect(json_encode($old, JSON_THROW_ON_ERROR))->not->toContain(INT_SECRET)->not->toContain($this->password);
    });
});
