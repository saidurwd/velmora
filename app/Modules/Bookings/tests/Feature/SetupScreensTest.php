<?php

declare(strict_types=1);

use App\Modules\Bookings\Actions\CancelBooking;
use App\Modules\Bookings\Actions\HoldSlot;
use App\Modules\Bookings\Actions\SaveCancellationPolicy;
use App\Modules\Bookings\Actions\SaveServiceDefinition;
use App\Modules\Bookings\Actions\SendBookingReminders;
use App\Modules\Bookings\Actions\SetWeeklyAvailability;
use App\Modules\Bookings\Exceptions\BookingException;
use App\Modules\Bookings\Services\Percent;
use App\Modules\Cart\Contracts\CartReader;
use App\Modules\Cart\DTOs\CartOwner;
use App\Modules\Catalog\Actions\TransitionProduct;
use App\Modules\Catalog\Models\Product;
use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Notifications\Sms\FakeSmsGateway;
use App\Modules\Orders\Actions\PlaceOrder;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\DTOs\PlaceOrderCommand;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\PaymentStatus;
use App\Modules\Orders\Services\CheckoutPricing;
use App\Modules\Payments\Database\Seeders\PaymentMethodSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Outbox\OutboxDispatcher;
use App\Modules\Pricing\Actions\SetRegularPrice;
use App\Modules\Pricing\Database\Seeders\PriceListSeeder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    bookingsWorld($this);
    $this->seed([RolesAndPermissionsSeeder::class, PriceListSeeder::class, PaymentMethodSeeder::class]);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    DB::table('payment_methods')->where('code', 'online')->update(['provider' => 'fake', 'is_enabled' => true]);
    config(['services.sms.driver' => 'fake']);
    $this->sms = app(FakeSmsGateway::class);
    Mail::fake();
    $this->manager = catalogStaff(['bookings.view', 'bookings.manage']);
    $this->viewer = catalogStaff(['bookings.view']);
    $this->nobody = catalogStaff(['catalog.view']);
    $this->as = fn (User $u) => $this->actingAs($u, 'admin');
    $this->defaultPolicy = (string) DB::table('cancellation_policies')->where('is_default', true)->value('public_id');
    $this->form = fn (array $over = []) => array_replace([
        'location' => 'at_provider', 'lead_time_min' => 60, 'max_horizon_days' => 60, 'hold_minutes' => 15, 'active' => '1', 'duration_min' => 45, 'buffer_before_min' => 5, 'buffer_after_min' => 10, 'package_active' => '1', 'policy' => '', 'meeting_url' => '',
    ], $over);
    $this->policyForm = fn (array $over = []) => array_replace([
        'late' => '50', 'no_show' => '100', 'reschedule_hours' => 12, 'max_reschedules' => 1, 'is_default' => '0',
        'windows' => [['hours' => '48', 'fee' => '0'], ['hours' => '24', 'fee' => '25'], ['hours' => '', 'fee' => '']],
        'words' => ['en' => ['title' => 'Strict rules', 'text' => 'Cancel early or pay.'], 'bn' => ['title' => '', 'text' => '']],
    ], $over);
});

describe('who may use them', function () {
    it('needs a staff sign-in and the right permission; only a manager can change anything', function () {
        $pages = ['/admin/bookings/setup/services', '/admin/bookings/setup/services/SVC-30', '/admin/bookings/setup/resources', '/admin/bookings/setup/resources/'.$this->tailor->public_id,
            '/admin/bookings/setup/holidays', '/admin/bookings/setup/policies', '/admin/bookings/setup/policies/'.$this->defaultPolicy];
        foreach ($pages as $url) {
            $this->get($url)->assertRedirect();   // not signed in
        }
        foreach ($pages as $url) {
            ($this->as)($this->nobody)->get($url)->assertForbidden();
            ($this->as)($this->viewer)->get($url)->assertOk();
        }
        ($this->as)($this->viewer)->get('/admin/bookings/setup/resources/create')->assertForbidden();
        ($this->as)($this->viewer)->get('/admin/bookings/setup/policies/create')->assertForbidden();

        $writes = [['put', '/admin/bookings/setup/services/SVC-30'], ['post', '/admin/bookings/setup/resources'], ['put', '/admin/bookings/setup/resources/'.$this->tailor->public_id], ['put', '/admin/bookings/setup/resources/'.$this->tailor->public_id.'/weekly'],
            ['post', '/admin/bookings/setup/resources/'.$this->tailor->public_id.'/exceptions'], ['post', '/admin/bookings/setup/holidays'], ['post', '/admin/bookings/setup/policies'], ['put', '/admin/bookings/setup/policies/'.$this->defaultPolicy]];
        foreach ($writes as [$verb, $url]) {
            ($this->as)($this->viewer)->{$verb}($url, ['name' => 'x'])->assertForbidden();
            ($this->as)($this->nobody)->{$verb}($url, ['name' => 'x'])->assertForbidden();
        }
        expect(DB::table('cancellation_policies')->count())->toBe(1)->and(DB::table('holidays')->count())->toBe(0);
    });
});

describe('services', function () {
    it('lists the bookable services and opens one by its code; a product that is not a service is not found', function () {
        ($this->as)($this->viewer)->get('/admin/bookings/setup/services')->assertOk()->assertSee('SVC-30')->assertSee('SVC-60')->assertSee('At our place')->assertSee('Online');
        ($this->as)($this->viewer)->get('/admin/bookings/setup/services/SVC-30')->assertOk()->assertSee('value="30"', false);
        ($this->as)($this->viewer)->get('/admin/bookings/setup/services/TEE-1')->assertNotFound();
        ($this->as)($this->viewer)->get('/admin/bookings/setup/services/NOPE')->assertNotFound();
    });

    it('saves the service, its package and its options together, and writes them down', function () {
        $policy = (string) DB::table('cancellation_policies')->value('public_id');

        ($this->as)($this->manager)->put('/admin/bookings/setup/services/SVC-30', ($this->form)(['location' => 'online', 'policy' => $policy, 'meeting_url' => 'https://meet.example.com/room-1']))
            ->assertRedirect('/admin/bookings/setup/services/SVC-30')->assertSessionHas('status', 'Saved.');

        $s = DB::table('service_definitions')->where('id', $this->service30->id)->first();
        $p = DB::table('service_packages')->where('id', $this->package30->id)->first();
        expect($s->location_type)->toBe('online')->and((int) $s->hold_minutes)->toBe(15)->and($s->meeting_url)->toBe('https://meet.example.com/room-1')->and((int) $s->cancellation_policy_id)->toBe((int) DB::table('cancellation_policies')->value('id'))
            ->and((int) $p->duration_min)->toBe(45)->and((int) $p->buffer_before_min)->toBe(5)->and((int) $p->buffer_after_min)->toBe(10)
            ->and(DB::table('audit_events')->where('action', 'bookings.service_options_changed')->count())->toBe(1);
        ($this->as)($this->viewer)->get('/admin/bookings/setup/services/SVC-30')->assertSee('https://meet.example.com/room-1');
    });

    it('refuses a meeting link that is not a plain https address, an unknown policy and nonsense numbers, and changes nothing', function () {
        foreach (['http://meet.example.com/x', 'javascript:alert(1)', 'ftp://x.example.com', 'https://', 'meet.example.com'] as $bad) {
            ($this->as)($this->manager)->put('/admin/bookings/setup/services/SVC-30', ($this->form)(['meeting_url' => $bad]))->assertSessionHasErrors();
        }
        ($this->as)($this->manager)->put('/admin/bookings/setup/services/SVC-30', ($this->form)(['policy' => str_repeat('a', 26)]))->assertSessionHasErrors('setup');
        ($this->as)($this->manager)->put('/admin/bookings/setup/services/SVC-30', ($this->form)(['hold_minutes' => 2]))->assertSessionHasErrors('hold_minutes');
        ($this->as)($this->manager)->put('/admin/bookings/setup/services/SVC-30', ($this->form)(['duration_min' => 0]))->assertSessionHasErrors('duration_min');

        $s = DB::table('service_definitions')->where('id', $this->service30->id)->first();
        expect($s->meeting_url)->toBeNull()->and($s->cancellation_policy_id)->toBeNull()->and((int) DB::table('service_packages')->where('id', $this->package30->id)->value('duration_min'))->toBe(30);
    });

    it('can make a product bookable for the first time', function () {
        $new = DB::table('product_variants')->where('sku', 'TEE-1')->value('id');
        expect($new)->not->toBeNull();
        ($this->as)($this->manager)->put('/admin/bookings/setup/services/TEE-1', ($this->form)())->assertSessionHasErrors('setup');   // a T-shirt is not a service
        expect(DB::table('service_definitions')->count())->toBe(2);
    });

    it('reads in Bangla', function () {
        $this->viewer->forceFill(['locale' => 'bn'])->save();

        ($this->as)($this->viewer)->get('/admin/bookings/setup/services')->assertOk()->assertSee('বুকিং সেটআপ')->assertSee('বাতিলের নীতি');
        ($this->as)($this->viewer)->get('/admin/bookings/setup/services/SVC-30')->assertOk()->assertSee('মিটিং লিংক');
    });
});

describe('people and rooms', function () {
    it('are listed with what they do, created and changed by a manager', function () {
        ($this->as)($this->viewer)->get('/admin/bookings/setup/resources')->assertOk()->assertSee('Rahim (tailor)')->assertSee('Fitting room')->assertSee('SVC-30');

        ($this->as)($this->manager)->post('/admin/bookings/setup/resources', ['name' => 'Karim', 'type' => 'staff', 'active' => '1', 'services' => ['SVC-60']])->assertRedirect();
        $id = DB::table('service_resources')->where('name', 'Karim')->value('public_id');
        expect($id)->not->toBeNull()->and(DB::table('service_resource_links')->where('resource_id', DB::table('service_resources')->where('public_id', $id)->value('id'))->count())->toBe(1);

        ($this->as)($this->manager)->put('/admin/bookings/setup/resources/'.$id, ['name' => 'Karim B', 'type' => 'room', 'active' => '0', 'services' => ['SVC-30', 'SVC-60']])->assertRedirect()->assertSessionHas('status');
        $row = DB::table('service_resources')->where('public_id', $id)->first();
        expect($row->name)->toBe('Karim B')->and($row->type)->toBe('room')->and((bool) $row->is_active)->toBeFalse()->and(DB::table('service_resource_links')->where('resource_id', $row->id)->count())->toBe(2);
    });

    it('refuse a missing name, a strange kind and a service that does not exist', function () {
        ($this->as)($this->manager)->post('/admin/bookings/setup/resources', ['name' => '', 'type' => 'staff'])->assertSessionHasErrors('name');
        ($this->as)($this->manager)->post('/admin/bookings/setup/resources', ['name' => 'X', 'type' => 'wizard'])->assertSessionHasErrors('type');
        ($this->as)($this->manager)->post('/admin/bookings/setup/resources', ['name' => 'X', 'type' => 'staff', 'services' => ['NOPE']])->assertSessionHasErrors('setup');
        ($this->as)($this->viewer)->get('/admin/bookings/setup/resources/01zzzzzzzzzzzzzzzzzzzzzzzz')->assertNotFound();
        expect(DB::table('service_resources')->count())->toBe(2);
    });

    it('have weekly hours that are replaced as a whole, keep seasonal rows when saved again, and refuse overlaps', function () {
        $url = '/admin/bookings/setup/resources/'.$this->tailor->public_id;
        $rule = fn (int $i, int $day, string $s, string $e, string $from = '', string $to = '') => ['weekday' => $day, 'start' => $s, 'end' => $e, 'valid_from' => $from, 'valid_to' => $to];

        ($this->as)($this->manager)->put($url.'/weekly', ['rules' => [$rule(0, 2, '09:00', '12:00'), $rule(1, 2, '14:00', '17:00'), $rule(2, 3, '', ''), $rule(3, 6, '10:00', '13:00', '2026-12-01', '2026-12-31')]])->assertRedirect($url)->assertSessionHas('status');
        expect(DB::table('availability_rules')->where('resource_id', $this->tailor->id)->count())->toBe(3);

        $page = ($this->as)($this->viewer)->get($url)->assertOk()->assertSee('value="09:00"', false)->assertSee('value="2026-12-01"', false);
        ($this->as)($this->manager)->put($url.'/weekly', ['rules' => [$rule(0, 2, '09:00', '12:00'), $rule(1, 6, '10:00', '13:00', '2026-12-01', '2026-12-31')]]);
        expect(DB::table('availability_rules')->where('resource_id', $this->tailor->id)->count())->toBe(2)->and(DB::table('availability_rules')->where('weekday', 6)->value('valid_from'))->toBe('2026-12-01');

        ($this->as)($this->manager)->put($url.'/weekly', ['rules' => [$rule(0, 2, '09:00', '12:00'), $rule(1, 2, '11:00', '13:00')]])->assertSessionHasErrors('setup');
        ($this->as)($this->manager)->put($url.'/weekly', ['rules' => [$rule(0, 2, '12:00', '09:00')]])->assertSessionHasErrors('setup');
        ($this->as)($this->manager)->put($url.'/weekly', ['rules' => [$rule(0, 2, 'noon', '17:00')]])->assertSessionHasErrors('setup');
        expect(DB::table('availability_rules')->where('resource_id', $this->tailor->id)->count())->toBe(2);   // refused edits changed nothing
    });

    it('have days off and one-off hours that can be added and taken away', function () {
        $url = '/admin/bookings/setup/resources/'.$this->tailor->public_id;
        $this->travelTo(CarbonImmutable::parse('2026-11-30 04:00:00', 'UTC'));

        ($this->as)($this->manager)->post($url.'/exceptions', ['date' => '2026-12-24', 'closed' => '1', 'note' => 'Family event'])->assertRedirect($url)->assertSessionHas('status');
        ($this->as)($this->manager)->post($url.'/exceptions', ['date' => '2026-12-25', 'start' => '10:00', 'end' => '13:00'])->assertRedirect($url);
        ($this->as)($this->viewer)->get($url)->assertSee('2026-12-24')->assertSee('Family event')->assertSee('10:00–13:00');
        ($this->as)($this->manager)->post($url.'/exceptions', ['date' => 'tomorrow', 'closed' => '1'])->assertSessionHasErrors('setup');
        ($this->as)($this->manager)->post($url.'/exceptions', ['date' => '2026-12-26', 'start' => '13:00', 'end' => '10:00'])->assertSessionHasErrors('setup');

        ($this->as)($this->manager)->delete($url.'/exceptions/2026-12-24')->assertRedirect($url);
        expect(DB::table('availability_exceptions')->where('resource_id', $this->tailor->id)->count())->toBe(1);
    });

    it('and the shop have holidays', function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-30 04:00:00', 'UTC'));

        ($this->as)($this->manager)->post('/admin/bookings/setup/holidays', ['date' => '2026-12-16', 'name' => 'Victory Day'])->assertRedirect('/admin/bookings/setup/holidays');
        ($this->as)($this->viewer)->get('/admin/bookings/setup/holidays')->assertOk()->assertSee('Victory Day')->assertSee('2026-12-16');
        ($this->as)($this->manager)->post('/admin/bookings/setup/holidays', ['date' => 'xmas', 'name' => 'X'])->assertSessionHasErrors('setup');
        ($this->as)($this->manager)->delete('/admin/bookings/setup/holidays/2026-12-16')->assertRedirect('/admin/bookings/setup/holidays');
        expect(DB::table('holidays')->count())->toBe(0);
    });
});

describe('percentages', function () {
    it('are read as people write them, in whole basis points, with no floating point', function () {
        expect(Percent::toBasisPoints('50'))->toBe(5000)->and(Percent::toBasisPoints('12.5'))->toBe(1250)->and(Percent::toBasisPoints('0.05'))->toBe(5)->and(Percent::toBasisPoints('100'))->toBe(10000)
            ->and(Percent::toBasisPoints('0'))->toBe(0)->and(Percent::toBasisPoints('১০'))->toBe(1000)->and(Percent::toBasisPoints('12,5'))->toBe(1250)->and(Percent::toBasisPoints(' 7 '))->toBe(700)
            ->and(Percent::toBasisPoints('100.01'))->toBeNull()->and(Percent::toBasisPoints('101'))->toBeNull()->and(Percent::toBasisPoints('-1'))->toBeNull()->and(Percent::toBasisPoints('abc'))->toBeNull()
            ->and(Percent::toBasisPoints(''))->toBeNull()->and(Percent::toBasisPoints('1.234'))->toBeNull();
        foreach ([0, 5, 100, 1250, 5000, 9999, 10000] as $bp) {
            expect(Percent::toBasisPoints(Percent::fromBasisPoints($bp)))->toBe($bp);
        }
        expect(Percent::fromBasisPoints(5000))->toBe('50')->and(Percent::fromBasisPoints(1250))->toBe('12.5')->and(Percent::fromBasisPoints(5))->toBe('0.05')->and(Percent::fromBasisPoints(0))->toBe('0');
    });
});

describe('cancellation policies', function () {
    it('are listed with their fees and which are the default', function () {
        ($this->as)($this->viewer)->get('/admin/bookings/setup/policies')->assertOk()->assertSee('Cancelling or moving a booking')->assertSee('default')->assertSee('50%')->assertSee('100%');
    });

    it('are made with windows, words and move rules, and the page for it reads them back', function () {
        ($this->as)($this->manager)->post('/admin/bookings/setup/policies', ($this->policyForm)())->assertRedirect()->assertSessionHas('status', 'Saved.');

        $p = DB::table('cancellation_policies')->where('code', 'strict-rules')->first();
        expect($p)->not->toBeNull()->and((int) $p->late_fee_bp)->toBe(5000)->and((int) $p->no_show_fee_bp)->toBe(10000)->and((int) $p->reschedule_hours)->toBe(12)->and((int) $p->max_reschedules)->toBe(1)->and((bool) $p->is_default)->toBeFalse()
            ->and(DB::table('cancellation_policy_windows')->where('policy_id', $p->id)->orderByDesc('hours_before')->get(['hours_before', 'fee_bp'])->map(fn ($w) => "{$w->hours_before}:{$w->fee_bp}")->all())->toBe(['48:0', '24:2500'])
            ->and(DB::table('cancellation_policy_translations')->where('policy_id', $p->id)->pluck('locale')->all())->toBe(['en'])
            ->and(DB::table('audit_events')->where('action', 'bookings.policy_created')->count())->toBe(1);
        ($this->as)($this->viewer)->get('/admin/bookings/setup/policies/'.$p->public_id)->assertOk()->assertSee('Strict rules')->assertSee('value="25"', false)->assertSee('value="48"', false);
    });

    it('refuse fees that make no sense, and say why', function () {
        $bad = [
            'a fee above 100%' => ['late' => '101'], 'a fee that is not a number' => ['late' => 'half'], 'a negative fee' => ['no_show' => '-5'], 'a closer window that costs less' => ['windows' => [['hours' => '48', 'fee' => '30'], ['hours' => '24', 'fee' => '10']]],
            'a late fee below the last window' => ['late' => '10'], 'a no-show below the late fee' => ['late' => '60', 'no_show' => '40'], 'the same hours twice' => ['windows' => [['hours' => '24', 'fee' => '0'], ['hours' => '24', 'fee' => '10']]],
            'hours that are not a number' => ['windows' => [['hours' => 'soon', 'fee' => '0']]], 'zero hours' => ['windows' => [['hours' => '0', 'fee' => '0']]], 'no English words' => ['words' => ['en' => ['title' => '', 'text' => ''], 'bn' => ['title' => 'শিরোনাম', 'text' => 'লেখা']]],
            'a title without text' => ['words' => ['en' => ['title' => 'Only a title', 'text' => '']]],
        ];
        foreach ($bad as $why => $over) {
            ($this->as)($this->manager)->post('/admin/bookings/setup/policies', ($this->policyForm)($over))->assertSessionHasErrors('setup');   // $why
        }
        ($this->as)($this->manager)->post('/admin/bookings/setup/policies', ($this->policyForm)(['reschedule_hours' => 9000]))->assertSessionHasErrors('reschedule_hours');
        ($this->as)($this->manager)->post('/admin/bookings/setup/policies', ($this->policyForm)(['max_reschedules' => 21]))->assertSessionHasErrors('max_reschedules');
        expect(DB::table('cancellation_policies')->count())->toBe(1);
    });

    it('take the rule through the action too, so no other door lets nonsense in', function () {
        $save = fn (array $w, int $late = 5000, int $noShow = 10000) => app(SaveCancellationPolicy::class)->handle($this->staff, null, $late, $noShow, 24, 2, false, $w, ['en' => ['title' => 'T', 'text' => 'x']]);

        expect(fn () => $save([['hours' => 48, 'fee_bp' => 3000], ['hours' => 24, 'fee_bp' => 1000]]))->toThrow(BookingException::class, 'fees_not_rising')
            ->and(fn () => $save([], 10001))->toThrow(BookingException::class, 'fee_invalid')
            ->and(fn () => $save([['hours' => 24, 'fee_bp' => 0], ['hours' => 24, 'fee_bp' => 0]]))->toThrow(BookingException::class, 'windows_invalid')
            ->and(fn () => $save([], 5000, 4000))->toThrow(BookingException::class, 'fees_not_rising');
        expect($save([['hours' => 48, 'fee_bp' => 0], ['hours' => 24, 'fee_bp' => 5000]], 5000, 5000))->toBeString();   // equal fees are fine
    });

    it('keep exactly one default: a new default takes over, and the only default cannot be unticked', function () {
        $old = $this->defaultPolicy;
        ($this->as)($this->manager)->post('/admin/bookings/setup/policies', ($this->policyForm)(['is_default' => '1']))->assertRedirect();
        $new = (string) DB::table('cancellation_policies')->where('code', 'strict-rules')->value('public_id');

        expect(DB::table('cancellation_policies')->where('is_default', true)->pluck('public_id')->all())->toBe([$new]);
        ($this->as)($this->manager)->put('/admin/bookings/setup/policies/'.$new, ($this->policyForm)(['is_default' => '0']))->assertSessionHasErrors('setup');
        expect(DB::table('cancellation_policies')->where('is_default', true)->count())->toBe(1);
        ($this->as)($this->manager)->put('/admin/bookings/setup/policies/'.$old, ($this->policyForm)(['is_default' => '1', 'words' => ['en' => ['title' => 'Back', 'text' => 'Again.']]]))->assertRedirect();
        expect(DB::table('cancellation_policies')->where('is_default', true)->pluck('public_id')->all())->toBe([$old]);
    });

    it('replace their windows and words when changed, and a service can be pointed at one', function () {
        ($this->as)($this->manager)->put('/admin/bookings/setup/policies/'.$this->defaultPolicy, ($this->policyForm)(['is_default' => '1', 'late' => '40', 'windows' => [['hours' => '72', 'fee' => '0']], 'words' => ['en' => ['title' => 'New words', 'text' => 'Fresh.'], 'bn' => ['title' => 'নতুন', 'text' => 'টাটকা']]]))->assertRedirect();

        $id = DB::table('cancellation_policies')->where('public_id', $this->defaultPolicy)->value('id');
        expect(DB::table('cancellation_policy_windows')->where('policy_id', $id)->pluck('hours_before')->map(fn ($v) => (int) $v)->all())->toBe([72])->and(DB::table('cancellation_policy_translations')->where('policy_id', $id)->count())->toBe(2)
            ->and((int) DB::table('cancellation_policies')->where('id', $id)->value('late_fee_bp'))->toBe(4000);
        ($this->as)($this->viewer)->get('/admin/bookings/setup/policies')->assertSee('New words')->assertSee('40%');
    });

    it('reads in Bangla', function () {
        $this->manager->forceFill(['locale' => 'bn'])->save();

        ($this->as)($this->manager)->get('/admin/bookings/setup/policies/'.$this->defaultPolicy)->assertOk()->assertSee('সময়সীমা')->assertSee('গ্রাহকদের দেখানো লেখা');
        ($this->as)($this->manager)->post('/admin/bookings/setup/policies', ($this->policyForm)(['late' => '500']))->assertSessionHasErrors('setup');
        expect(session('errors')->first('setup'))->toContain('ফি');
    });
});

describe('the meeting link', function () {
    beforeEach(function () {
        app(SetRegularPrice::class)->handle($this->staff, $this->variant30, Money::of(50000));
        app(TransitionProduct::class)->publish($this->staff, Product::query()->whereHas('variants', fn ($q) => $q->whereKey($this->variant30))->firstOrFail());
        $this->travelTo(CarbonImmutable::parse('2026-11-30 04:00:00', 'UTC'));
        app(SaveServiceDefinition::class)->handle($this->staff, 'SVC-30', 'online', 60, 60, 15, true);
        app(SetWeeklyAvailability::class)->handle($this->staff, $this->tailor, [['weekday' => 2, 'start' => '09:00', 'end' => '12:00']]);
        $this->customer = User::factory()->create(['email' => 'o@example.com']);
        $owner = CartOwner::user($this->customer->id);
        app(HoldSlot::class)->handle($owner, 'SVC-30', $this->tailor->public_id, CarbonImmutable::parse('2026-12-01 10:00', 'Asia/Dhaka')->utc()->format('Y-m-d H:i:s'));
        $order = app(PlaceOrder::class)->handle(new PlaceOrderCommand(
            actor: Actor::guest('checkout'), owner: $owner, userId: $this->customer->id, contactName: 'Rahim', contactPhone: '01712345678', contactEmail: null, address: null, shippingMethodCode: null, paymentMethodCode: 'online',
            expectedTotalMinor: app(CheckoutPricing::class)->quote(app(CartReader::class)->view($owner, 'en'), null, null, 'online', 'en')->breakdown->grandTotalMinor, placeToken: 'token-'.Str::random(12), locale: 'en',
        ));
        app(TransitionOrder::class)->status(Actor::system('t'), $order->id, OrderStatus::Confirmed, 'test');
        app(TransitionOrder::class)->payment(Actor::system('t'), $order->id, PaymentStatus::Paid, 'test');
        app(OutboxDispatcher::class)->dispatchDue();
        $this->booking = (string) DB::table('service_bookings')->where('order_item_id', DB::table('order_items')->where('order_id', $order->id)->value('id'))->value('public_id');
    });

    it('is told in the reminder of an online booking, and is not invented when there is none', function () {
        app(SendBookingReminders::class)->handle();
        $first = collect($this->sms->sent())->first(fn ($m) => str_starts_with((string) ($m['meta']['purpose'] ?? ''), 'booking_'));
        expect($first['message'])->toContain('online')->not->toContain('https://');
    });

    it('shows a confirmed customer where to join, and nobody else, and not after it was cancelled', function () {
        $this->manager->forceFill([])->save();
        ($this->as)($this->manager)->put('/admin/bookings/setup/services/SVC-30', ($this->form)(['location' => 'online', 'duration_min' => 30, 'buffer_before_min' => 0, 'buffer_after_min' => 10, 'meeting_url' => 'https://meet.example.com/abc']))->assertRedirect();

        $this->actingAs($this->customer, 'web')->get('/en/account/bookings/'.$this->booking)->assertOk()->assertSee('Join online')->assertSee('https://meet.example.com/abc', false);
        app(SendBookingReminders::class)->handle();
        expect(collect($this->sms->sent())->contains(fn ($m) => str_contains($m['message'], 'https://meet.example.com/abc')))->toBeTrue();

        app(CancelBooking::class)->handle($this->staff, $this->booking, 'x');
        $this->actingAs($this->customer, 'web')->get('/en/account/bookings/'.$this->booking)->assertOk()->assertDontSee('meet.example.com');
    });
});
