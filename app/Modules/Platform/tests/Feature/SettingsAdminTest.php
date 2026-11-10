<?php

declare(strict_types=1);

use App\Modules\Engagement\Actions\SendCartReminders;
use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Platform\Actions\SaveSettingsGroup;
use App\Modules\Platform\Actions\SetFeatureFlag;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Exceptions\SettingsException;
use App\Modules\Platform\Support\BusinessProfile;
use App\Modules\Platform\Support\FeatureFlags;
use App\Modules\Platform\Support\Settings;
use App\Modules\Platform\Support\SettingsGroups;
use App\Modules\Platform\Support\SettingsWriter;
use App\Modules\Platform\Support\StorefrontCache;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\PermissionRegistrar;

/*
 * Phase 10, slice 4a: the settings screens (business profile, tax switches, currency and language), feature flags and maintenance mode.
 */

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, LanguageSeeder::class]);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    RateLimiter::clear('reauth:1');
    $this->password = 'Tr0ub4dor-and-3-horses';
    $this->manager = User::factory()->staff()->create(['password' => $this->password]);
    foreach (['settings.view', 'settings.manage', 'features.manage'] as $p) {
        $this->manager->givePermissionTo($p);
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actor = Actor::system('t');
    $this->settings = fn () => app(Settings::class);
    $this->version = fn (string $group) => app(SettingsWriter::class)->version(SettingsGroups::keys($group));
    $this->business = fn (array $over = []) => array_replace_recursive([
        'version' => ($this->version)('business'), 'phone' => '+880 1712 345678', 'email' => 'Hello@Shop.example', 'bin' => '000123456-0101', 'tin' => '123456789012',
        'texts' => ['en' => ['legal_name' => 'Velmora Trading', 'address' => "House 5, Road 2\nDhanmondi, Dhaka", 'invoice_footer' => 'Thank you.'], 'bn' => ['legal_name' => 'ভেলমোরা ট্রেডিং', 'address' => 'বাড়ি ৫, সড়ক ২, ধানমন্ডি', 'invoice_footer' => 'ধন্যবাদ।']],
    ], $over);
    $this->audits = fn (string $action) => DB::table('audit_events')->where('action', $action)->orderBy('id')->get();
});

describe('the business profile', function () {
    it('prints nothing while empty, and only what was filled in once saved', function () {
        expect(trim(Blade::render('<x-platform::business-block />')))->toBe('');   // nothing at all, not even an empty box

        $this->actingAs($this->manager, 'admin')->put('/admin/settings/business', ['version' => ($this->version)('business'), 'texts' => ['en' => ['legal_name' => 'Velmora Trading']]])->assertRedirect('/admin/settings/business');
        $html = Blade::render('<x-platform::business-block />');
        expect($html)->toContain('Velmora Trading')->not->toContain('BIN')->not->toContain('TIN');

        $this->actingAs($this->manager, 'admin')->put('/admin/settings/business', ($this->business)())->assertRedirect('/admin/settings/business');
        $html = Blade::render('<x-platform::business-block />');
        expect($html)->toContain('Velmora Trading')->toContain('Dhanmondi, Dhaka')->toContain('+880 1712 345678')->toContain('hello@shop.example')->toContain('BIN: 000123456-0101')->toContain('TIN: 123456789012')->toContain('Thank you.');
    });

    it('shows each language its own text and the English one where a language has none', function () {
        $this->actingAs($this->manager, 'admin')->put('/admin/settings/business', ($this->business)(['texts' => ['bn' => ['invoice_footer' => '']]]))->assertRedirect();

        app()->setLocale('bn');
        $bn = Blade::render('<x-platform::business-block />');
        expect($bn)->toContain('ভেলমোরা ট্রেডিং')->toContain('বাড়ি ৫')->toContain('Thank you.')->toContain('BIN:')->not->toContain('ধন্যবাদ');   // no Bangla footer: the English one
        app()->setLocale('en');
        expect(app(BusinessProfile::class)->legalName('bn'))->toBe('ভেলমোরা ট্রেডিং')->and(app(BusinessProfile::class)->legalName('en'))->toBe('Velmora Trading');
    });

    it('escapes what was typed, and lets a field be cleared again', function () {
        $this->actingAs($this->manager, 'admin')->put('/admin/settings/business', ($this->business)(['texts' => ['en' => ['legal_name' => '<script>alert(1)</script> Shop']]]))->assertRedirect();
        $html = Blade::render('<x-platform::business-block />');
        expect($html)->not->toContain('<script>')->toContain('&lt;script&gt;');

        $this->actingAs($this->manager, 'admin')->put('/admin/settings/business', ['version' => ($this->version)('business')])->assertRedirect();   // everything blank
        expect(app(BusinessProfile::class)->isEmpty('en'))->toBeTrue()->and(Blade::render('<x-platform::business-block />'))->not->toContain('Shop');
    });

    it('refuses a value that is not valid, says which field, and saves nothing', function () {
        $before = ($this->version)('business');
        $put = fn (array $over) => $this->actingAs($this->manager, 'admin')->from('/admin/settings/business')->put('/admin/settings/business', ($this->business)($over));

        $put(['email' => 'not an email'])->assertRedirect('/admin/settings/business')->assertSessionHasErrors('email');
        $put(['bin' => 'ABC123'])->assertSessionHasErrors('bin');
        $put(['tin' => '12 34'])->assertSessionHasErrors('tin');
        $put(['phone' => 'call me'])->assertSessionHasErrors('phone');
        $put(['texts' => ['en' => ['legal_name' => str_repeat('a', 121)]]])->assertSessionHasErrors('legal_name_en');
        $put(['texts' => ['bn' => ['address' => str_repeat('ক', 401)]]])->assertSessionHasErrors('address_bn');
        $put(['texts' => ['en' => ['invoice_footer' => str_repeat('a', 301)]]])->assertSessionHasErrors('invoice_footer_en');

        expect(($this->version)('business'))->toBe($before)->and(DB::table('settings')->where('key', 'like', 'business.%')->count())->toBe(0)->and(($this->audits)('settings.business_updated'))->toHaveCount(0);
    });

    it('records what changed, before and after, in the audit log', function () {
        $this->actingAs($this->manager, 'admin')->put('/admin/settings/business', ($this->business)())->assertRedirect();
        $this->actingAs($this->manager, 'admin')->put('/admin/settings/business', ($this->business)(['texts' => ['en' => ['legal_name' => 'Velmora Ltd']]]))->assertRedirect();

        $rows = ($this->audits)('settings.business_updated');
        $second = $rows[1];
        expect($rows)->toHaveCount(2)->and($second->actor_id)->toBe($this->manager->id)
            ->and(json_decode($second->before, true)['business.legal_name.en'])->toBe('Velmora Trading')->and(json_decode($second->after, true)['business.legal_name.en'])->toBe('Velmora Ltd');
    });

    it('turns away a save made from an out-of-date form, and accepts it from a fresh one', function () {
        $stale = ($this->business)();
        $this->actingAs($this->manager, 'admin')->put('/admin/settings/business', ($this->business)(['texts' => ['en' => ['legal_name' => 'First person']]]))->assertRedirect();

        $this->actingAs($this->manager, 'admin')->from('/admin/settings/business')->put('/admin/settings/business', $stale)->assertRedirect('/admin/settings/business')->assertSessionHasErrors('settings');
        expect(app(BusinessProfile::class)->legalName('en'))->toBe('First person');

        $this->actingAs($this->manager, 'admin')->put('/admin/settings/business', ($this->business)(['texts' => ['en' => ['legal_name' => 'Second person']]]))->assertRedirect();
        expect(app(BusinessProfile::class)->legalName('en'))->toBe('Second person');
        $this->actingAs($this->manager, 'admin')->put('/admin/settings/business', ['phone' => '017'])->assertSessionHasErrors('version');   // no version at all
    });

    it('shows the saved values in the form, in both languages, and is closed to those without the right', function () {
        $this->actingAs($this->manager, 'admin')->put('/admin/settings/business', ($this->business)())->assertRedirect();
        $this->actingAs($this->manager, 'admin')->get('/admin/settings/business')->assertOk()->assertSee('Business profile')->assertSee('Velmora Trading')->assertSee('000123456-0101')->assertSee('বাড়ি ৫, সড়ক ২, ধানমন্ডি');

        $this->manager->forceFill(['locale' => 'bn'])->save();
        $this->actingAs($this->manager->fresh(), 'admin')->get('/admin/settings/business')->assertOk()->assertSee('ব্যবসার তথ্য');

        $viewer = catalogStaff(['settings.view']);
        $this->actingAs($viewer, 'admin')->get('/admin/settings/business')->assertOk();
        $this->actingAs($viewer, 'admin')->put('/admin/settings/business', ($this->business)())->assertForbidden();
        $this->actingAs(catalogStaff(['catalog.view']), 'admin')->get('/admin/settings/business')->assertForbidden();
        auth('admin')->logout();
        $this->flushSession();
        $this->get('/admin/settings/business')->assertRedirect();
    });
});

describe('the tax switches', function () {
    it('start off, save together, and are audited', function () {
        $this->actingAs($this->manager, 'admin')->get('/admin/settings/tax')->assertOk()->assertSee('Do not turn tax on until your tax adviser has confirmed');
        expect(($this->settings)()->bool('tax.enabled'))->toBeFalse();

        $cacheBefore = app(StorefrontCache::class)->version();
        $this->actingAs($this->manager, 'admin')->put('/admin/settings/tax', ['version' => ($this->version)('tax'), 'enabled' => '1', 'shipping_taxable' => '1'])->assertRedirect('/admin/settings/tax');
        expect(app(StorefrontCache::class)->version())->toBeGreaterThan($cacheBefore);   // prices on cached shop pages are worked out again

        expect(($this->settings)()->bool('tax.enabled'))->toBeTrue()->and(($this->settings)()->bool('tax.shipping_taxable'))->toBeTrue()
            ->and(($this->settings)()->bool('tax.prices_include_tax', true))->toBeFalse()   // left unticked: now off (it was on by default)
            ->and(($this->settings)()->bool('tax.cod_fee_taxable'))->toBeFalse();
        $after = json_decode(($this->audits)('settings.tax_updated')[0]->after, true);
        expect($after)->toBe(['tax.enabled' => true, 'tax.prices_include_tax' => false, 'tax.shipping_taxable' => true, 'tax.cod_fee_taxable' => false]);
        $this->actingAs($this->manager, 'admin')->get('/admin/settings/tax')->assertOk()->assertSee('checked', false);
    });

    it('turns away a stale form, and is closed to those without the right to manage', function () {
        $stale = ['version' => ($this->version)('tax'), 'enabled' => '1'];
        $this->actingAs($this->manager, 'admin')->put('/admin/settings/tax', ['version' => $stale['version'], 'prices_include_tax' => '1'])->assertRedirect();

        $this->actingAs($this->manager, 'admin')->from('/admin/settings/tax')->put('/admin/settings/tax', $stale)->assertSessionHasErrors('settings');
        expect(($this->settings)()->bool('tax.enabled'))->toBeFalse();
        $this->actingAs(catalogStaff(['settings.view']), 'admin')->put('/admin/settings/tax', ['version' => ($this->version)('tax')])->assertForbidden();
    });

    it('reads in Bangla', function () {
        $this->manager->forceFill(['locale' => 'bn'])->save();
        $this->actingAs($this->manager->fresh(), 'admin')->get('/admin/settings/tax')->assertOk()->assertSee('কর নিন')->assertSee('কর পরামর্শক');
    });
});

describe('currency and languages', function () {
    it('are shown and cannot be changed here', function () {
        $this->actingAs($this->manager, 'admin')->get('/admin/settings/locale')->assertOk()->assertSee('BDT')->assertSee('English')->assertSee('বাংলা');
        $this->actingAs($this->manager, 'admin')->put('/admin/settings/locale', ['currency' => 'USD'])->assertStatus(405);
        $this->actingAs($this->manager, 'admin')->get('/admin/settings')->assertOk()->assertSee('Business profile')->assertSee('Maintenance mode')->assertDontSee('Audit log');   // audit.view is separate
        expect(DB::table('settings')->where('key', 'business.currency')->count())->toBe(0);
    });
});

describe('feature switches', function () {
    it('follow the server\'s setting until staff set them, and go back when reset', function () {
        $flags = app(FeatureFlags::class);
        config(['engagement.cart_recovery.enabled' => false]);
        expect($flags->enabled('cart_recovery'))->toBeFalse()->and($flags->isSetByStaff('cart_recovery'))->toBeFalse();

        config(['engagement.cart_recovery.enabled' => true]);
        expect($flags->enabled('cart_recovery'))->toBeTrue();

        $this->actingAs($this->manager, 'admin')->put('/admin/features/cart_recovery', ['value' => 'off'])->assertRedirect('/admin/features');
        expect($flags->enabled('cart_recovery'))->toBeFalse()->and($flags->isSetByStaff('cart_recovery'))->toBeTrue();   // staff beat the server's "on"

        $this->actingAs($this->manager, 'admin')->put('/admin/features/cart_recovery', ['value' => 'on'])->assertRedirect();
        config(['engagement.cart_recovery.enabled' => false]);
        expect($flags->enabled('cart_recovery'))->toBeTrue();                       // and beat the server's "off"

        $this->actingAs($this->manager, 'admin')->put('/admin/features/cart_recovery', ['value' => 'default'])->assertRedirect();
        expect($flags->enabled('cart_recovery'))->toBeFalse()->and($flags->isSetByStaff('cart_recovery'))->toBeFalse()->and(DB::table('settings')->where('key', 'feature.cart_recovery')->count())->toBe(0);
        expect(($this->audits)('features.changed'))->toHaveCount(2)->and(($this->audits)('features.reset'))->toHaveCount(1);
    });

    it('exist only if the code lists them, and none can touch live payments', function () {
        foreach (['payments_live', 'allow_live', 'unknown'] as $name) {
            $this->actingAs($this->manager, 'admin')->put("/admin/features/{$name}", ['value' => 'on'])->assertClientError();   // no such route: refused, nothing stored
        }
        $this->actingAs($this->manager, 'admin')->put('/admin/features/cart_recovery', ['value' => 'maybe'])->assertSessionHasErrors('value');
        expect(fn () => app(SetFeatureFlag::class)->handle($this->actor, 'payments_live', true))->toThrow(SettingsException::class)
            ->and(fn () => FeatureFlags::settingKey('nope'))->toThrow(InvalidArgumentException::class)
            ->and(DB::table('settings')->where('key', 'like', 'feature.%')->count())->toBe(0);
        foreach (FeatureFlags::names() as $name) {
            expect($name)->not->toMatch('/pay|live|money|refund/');
        }
    });

    it('can be seen by those who view settings but changed only with the features right', function () {
        $viewer = catalogStaff(['settings.view']);
        $this->actingAs($viewer, 'admin')->get('/admin/features')->assertOk()->assertSee('Forgotten-cart reminders')->assertDontSee('Turn on');
        $this->actingAs($viewer, 'admin')->put('/admin/features/cart_recovery', ['value' => 'on'])->assertForbidden();
        $this->actingAs(catalogStaff(['settings.view', 'settings.manage']), 'admin')->put('/admin/features/cart_recovery', ['value' => 'on'])->assertForbidden();   // managing settings is not managing switches
        $this->actingAs($this->manager, 'admin')->get('/admin/features')->assertOk()->assertSee('Turn on')->assertSee('Follows the server');
        expect(app(FeatureFlags::class)->isSetByStaff('cart_recovery'))->toBeFalse();
    });

    it('decide whether the cart reminders run at all', function () {
        $queries = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $sent = app(SendCartReminders::class)->handle();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return [$sent, $n];
        };
        config(['engagement.cart_recovery.enabled' => true]);
        app(SetFeatureFlag::class)->handle($this->actor, 'cart_recovery', false);
        expect($queries()[1])->toBeLessThanOrEqual(1);       // off by staff: it reads the switch and stops (no carts looked at)

        app(SetFeatureFlag::class)->handle($this->actor, 'cart_recovery', true);
        expect($queries()[1])->toBeGreaterThan(1);           // on by staff: it goes on to look at the carts (and finds nothing to send here)
    });

    it('reads in Bangla', function () {
        $this->manager->forceFill(['locale' => 'bn'])->save();
        $this->actingAs($this->manager->fresh(), 'admin')->get('/admin/features')->assertOk()->assertSee('ফেলে যাওয়া কার্টের অনুস্মারক')->assertSee('চালু করুন');
    });
});

describe('maintenance mode', function () {
    afterEach(fn () => Artisan::call('up'));

    it('closes the storefront, keeps the admin area and the health checks open, and opens again', function () {
        $this->get('/en')->assertOk();

        $this->actingAs($this->manager, 'admin')->post('/admin/maintenance/enable', ['password' => $this->password])->assertRedirect('/admin/maintenance')->assertSessionHas('bypass_url');
        expect(app()->isDownForMaintenance())->toBeTrue();

        $this->get('/en')->assertStatus(503)->assertSee('Back soon', false);
        $this->get('/healthz')->assertOk();
        $this->actingAs($this->manager, 'admin')->get('/admin/maintenance')->assertOk()->assertSee('closed for maintenance');
        $this->actingAs($this->manager, 'admin')->get('/admin/settings')->assertOk();   // the admin area is not shut

        $this->actingAs($this->manager, 'admin')->post('/admin/maintenance/disable', ['password' => $this->password])->assertRedirect('/admin/maintenance');
        expect(app()->isDownForMaintenance())->toBeFalse();
        $this->get('/en')->assertOk();
        expect(($this->audits)('settings.maintenance_enabled'))->toHaveCount(1)->and(($this->audits)('settings.maintenance_disabled'))->toHaveCount(1)->and(($this->audits)('settings.maintenance_enabled')[0]->actor_id)->toBe($this->manager->id);
    });

    it('gives the person who closed it a link to browse the shop meanwhile, shown once', function () {
        $response = $this->actingAs($this->manager, 'admin')->post('/admin/maintenance/enable', ['password' => $this->password]);
        $url = session('bypass_url');
        expect($url)->toStartWith(url('/'))->and(strlen(basename($url)))->toBe(40);

        $this->get('/en')->assertStatus(503);
        $cookie = $this->get(parse_url($url, PHP_URL_PATH))->assertRedirect('/')->getCookie('laravel_maintenance', false);   // the maintenance cookie is not an encrypted one
        expect($cookie)->not->toBeNull();
        $this->withUnencryptedCookie('laravel_maintenance', $cookie->getValue())->get('/en')->assertOk();   // with the link's cookie the shop opens for them
        $this->actingAs($this->manager, 'admin')->get('/admin/maintenance')->assertOk()->assertDontSee(basename($url));   // and the link is not shown again
        expect($response->status())->toBe(302);
    });

    it('needs the staff member\'s own password, and gives up after too many wrong tries', function () {
        $as = fn () => $this->actingAs($this->manager, 'admin');
        $as()->post('/admin/maintenance/enable', ['password' => 'wrong'])->assertSessionHasErrors('password');
        expect(app()->isDownForMaintenance())->toBeFalse();

        foreach (range(2, 5) as $i) {   // five wrong tries in all (the route allows six requests a minute, so the password limit is what stops the sixth)
            $as()->post('/admin/maintenance/enable', ['password' => 'wrong'.$i])->assertSessionHasErrors('password');
        }
        $as()->post('/admin/maintenance/enable', ['password' => $this->password])->assertSessionHasErrors('password');   // locked for a while, even with the right one
        expect(app()->isDownForMaintenance())->toBeFalse();
    });

    it('cannot be started twice or stopped when open, and is closed to those without the right', function () {
        $as = fn () => $this->actingAs($this->manager, 'admin');
        $as()->post('/admin/maintenance/disable', ['password' => $this->password])->assertSessionHasErrors('maintenance');
        $as()->post('/admin/maintenance/enable', ['password' => $this->password])->assertRedirect();
        RateLimiter::clear('reauth:'.$this->manager->id);
        $as()->post('/admin/maintenance/enable', ['password' => $this->password])->assertSessionHasErrors('maintenance');
        expect(($this->audits)('settings.maintenance_enabled'))->toHaveCount(1);

        $viewer = catalogStaff(['settings.view']);
        $this->actingAs($viewer, 'admin')->get('/admin/maintenance')->assertOk();
        $this->actingAs($viewer, 'admin')->post('/admin/maintenance/disable', ['password' => 'x'])->assertForbidden();
        $this->actingAs($viewer, 'admin')->post('/admin/maintenance/enable', ['password' => 'x'])->assertForbidden();   // and cannot close the shop
        expect(app()->isDownForMaintenance())->toBeTrue();   // only the manager's earlier closing is in effect
        $this->actingAs(catalogStaff(['catalog.view']), 'admin')->get('/admin/maintenance')->assertForbidden();
    });

    it('does not let a customer, or a staff member of another account, confirm with someone else\'s password', function () {
        $other = User::factory()->staff()->create(['password' => 'An0ther-long-secret-9']);
        $other->givePermissionTo('settings.manage');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($other, 'admin')->post('/admin/maintenance/enable', ['password' => $this->password])->assertSessionHasErrors('password');   // the manager's password is not theirs
        expect(app()->isDownForMaintenance())->toBeFalse();
    });

    it('reads in Bangla', function () {
        $this->manager->forceFill(['locale' => 'bn'])->save();
        $this->actingAs($this->manager->fresh(), 'admin')->get('/admin/maintenance')->assertOk()->assertSee('রক্ষণাবেক্ষণ মোড')->assertSee('খোলা');
    });
});

describe('the strings', function () {
    it('are the same keys in English and Bangla', function () {
        $keys = function (array $a, string $prefix = '') use (&$keys): array {
            $out = [];
            foreach ($a as $k => $v) {
                is_array($v) ? array_push($out, ...$keys($v, "{$prefix}{$k}.")) : $out[] = "{$prefix}{$k}";
            }

            return $out;
        };
        foreach (['settings', 'audit'] as $file) {
            $en = $keys(require app_path("Modules/Platform/resources/lang/en/{$file}.php"));
            $bn = $keys(require app_path("Modules/Platform/resources/lang/bn/{$file}.php"));
            expect(array_values(array_diff($en, $bn)))->toBe([])->and(array_values(array_diff($bn, $en)))->toBe([]);
        }
    });

    it('rejects a group that does not exist', function () {
        expect(fn () => app(SaveSettingsGroup::class)->handle($this->actor, 'payments', [], 'x'))->toThrow(SettingsException::class, 'unknown');   // refused as unknown, not merely as out of date
    });
});
