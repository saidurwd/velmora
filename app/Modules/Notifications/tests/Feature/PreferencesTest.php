<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Notifications\Actions\SaveMessagePreferences;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed([LanguageSeeder::class, RolesAndPermissionsSeeder::class]);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    $this->user = User::factory()->create(['email' => 'amina@example.com']);
    $this->other = User::factory()->create(['email' => 'other@example.com']);
    $this->save = fn (array $over = []) => $this->actingAs($this->user, 'web')->put('/en/account/preferences', $over);
    $this->events = fn (?User $u = null) => DB::table('notification_consent_events')->where('user_id', ($u ?? $this->user)->id)->orderBy('id')->get(['channel', 'granted', 'source'])->map(fn ($e) => "{$e->channel}/".($e->granted ? 'yes' : 'no')."/{$e->source}")->all();
});

describe('the preferences page', function () {
    it('is for signed-in customers only', function () {
        $this->get('/en/account/preferences')->assertRedirect('/en/login');
        $this->put('/en/account/preferences', ['email' => '1'])->assertRedirect('/en/login');
    });

    it('shows the current answers and says that messages about their own orders always go', function () {
        $this->actingAs($this->user, 'web')->get('/en/account/preferences')->assertOk()->assertSee('Messages & preferences')->assertSee('always sent');
        app(SaveMessagePreferences::class)->handle($this->user->id, ['email' => true, 'sms' => false], '22:00', '07:00');

        $html = $this->actingAs($this->user, 'web')->get('/en/account/preferences')->getContent();
        expect($html)->toMatch('~name="email"[^>]*checked~')->and($html)->not->toMatch('~name="sms"[^>]*checked~')->and($html)->toContain('value="22:00"')->and($html)->toContain('value="07:00"');
    });

    it('is shown in Bangla with no missing words', function () {
        $html = $this->actingAs($this->user, 'web')->get('/bn/account/preferences')->assertOk()->getContent();

        expect($html)->toContain('বার্তা ও পছন্দ')->and($html)->not->toContain('notifications::');
    });
});

describe('saving a choice', function () {
    it('records a yes with the time and where it was given, and keeps the history', function () {
        ($this->save)(['email' => '1', 'sms' => '1'])->assertSessionHas('status');

        expect(DB::table('notification_preferences')->where('user_id', $this->user->id)->orderBy('channel')->pluck('marketing_opt_in', 'channel')->map(fn ($v) => (bool) $v)->all())->toBe(['email' => true, 'sms' => true])
            ->and(($this->events)())->toBe(['email/yes/account', 'sms/yes/account'])
            ->and(DB::table('notification_preferences')->where('user_id', $this->user->id)->whereNull('opted_at')->count())->toBe(0);
        expect(DB::table('audit_events')->where('action', 'notifications.consent_changed')->count())->toBe(2);
    });

    it('treats an unticked box as a no, and records the change from yes to no', function () {
        ($this->save)(['email' => '1', 'sms' => '1']);
        ($this->save)(['email' => '1']);   // sms left unticked: withdrawn

        expect(($this->events)())->toBe(['email/yes/account', 'sms/yes/account', 'sms/no/account'])
            ->and((bool) DB::table('notification_preferences')->where('user_id', $this->user->id)->where('channel', 'sms')->value('marketing_opt_in'))->toBeFalse();
    });

    it('records nothing new when the same answer is saved again, or when the first answer is a no', function () {
        ($this->save)([]);   // a first "no" on both
        expect(($this->events)())->toBe([]);
        ($this->save)(['email' => '1']);
        ($this->save)(['email' => '1']);
        ($this->save)(['email' => '1']);

        expect(($this->events)())->toBe(['email/yes/account']);
    });

    it('keeps quiet hours for both channels, accepts Bangla digits, and clears them', function () {
        ($this->save)(['quiet_from' => '২২:০০', 'quiet_to' => '০৭:০০']);
        $rows = DB::table('notification_preferences')->where('user_id', $this->user->id)->get();
        expect($rows->pluck('quiet_from')->unique()->all())->toBe(['22:00:00'])->and($rows->pluck('quiet_to')->unique()->all())->toBe(['07:00:00']);

        ($this->save)(['quiet_from' => '', 'quiet_to' => '']);
        expect(DB::table('notification_preferences')->where('user_id', $this->user->id)->whereNotNull('quiet_from')->count())->toBe(0);
    });

    it('refuses half a quiet-hours window, the same time twice, and nonsense', function () {
        foreach ([['quiet_from' => '22:00'], ['quiet_to' => '07:00'], ['quiet_from' => '08:00', 'quiet_to' => '08:00'], ['quiet_from' => '25:99', 'quiet_to' => '07:00']] as $bad) {
            ($this->save)($bad)->assertSessionHasErrors();
        }
        expect(DB::table('notification_preferences')->where('user_id', $this->user->id)->count())->toBe(0);
    });

    it('only ever changes the signed-in customer, whatever else is sent', function () {
        ($this->save)(['email' => '1', 'user_id' => $this->other->id, 'user' => $this->other->public_id]);

        expect(DB::table('notification_preferences')->where('user_id', $this->other->id)->count())->toBe(0)->and(($this->events)($this->other))->toBe([]);
    });

    it('is throttled like other writes', function () {
        foreach (range(1, 60) as $i) {
            ($this->save)(['email' => '1']);
        }

        ($this->save)(['email' => '1'])->assertStatus(429);
    });
});

describe('the history of answers', function () {
    it('cannot be edited or deleted, not even by the database user', function () {
        ($this->save)(['email' => '1']);

        expect(fn () => DB::table('notification_consent_events')->update(['granted' => false]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('notification_consent_events')->delete())->toThrow(QueryException::class);
    });

    it('is shown to the customer, newest first, and only their own', function () {
        ($this->save)(['email' => '1']);
        $this->actingAs($this->other, 'web')->put('/en/account/preferences', ['sms' => '1']);
        ($this->save)([]);

        $html = $this->actingAs($this->user, 'web')->get('/en/account/preferences')->getContent();
        expect($html)->toContain('Your recent answers')->and(substr_count($html, 'by email'))->toBeGreaterThanOrEqual(2)->and($html)->not->toContain('Send me offers and news by text message: yes');
    });

    it('rejects an unknown channel or half a window when called directly', function () {
        expect(fn () => app(SaveMessagePreferences::class)->handle($this->user->id, ['whatsapp' => true], null, null))->toThrow(InvalidArgumentException::class)
            ->and(fn () => app(SaveMessagePreferences::class)->handle($this->user->id, [], '22:00', null))->toThrow(InvalidArgumentException::class);
    });
});

it('has the same words in English and Bangla', function () {
    $en = require app_path('Modules/Notifications/resources/lang/en/account.php');
    $bn = require app_path('Modules/Notifications/resources/lang/bn/account.php');

    expect(array_keys($en))->toBe(array_keys($bn));
});
