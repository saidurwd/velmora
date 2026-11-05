<?php

declare(strict_types=1);

use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Privacy\Actions\DecideErasure;
use App\Modules\Privacy\Actions\RequestErasure;
use App\Modules\Privacy\Actions\RunErasure;
use App\Modules\Privacy\Contracts\PersonalDataEraser;
use App\Modules\Privacy\Services\PersonalDataCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    privacyWorld($this);
    $this->amina = ($this->makeCustomer)('Amina Rahman', 'amina@example.com', '+8801711111111');
    $this->bob = ($this->makeCustomer)('Bob Karim', 'bob@example.com', '+8801822222222');
    $this->staff = new Actor(ActorType::Staff, $this->manager->id, $this->manager->public_id, 'Manager');
    /** Asks for the erasure and has it approved (the orders are finished first), exactly as the screens do. */
    $this->erase = function (?array $who = null) {
        $who ??= $this->amina;
        if (DB::table('orders')->where('id', $who['order']->id)->value('status') !== 'completed') {
            ($this->finish)($who['order']->id);
        }
        $request = app(RequestErasure::class)->handle($who['user']->id, 'leaving', 'en');
        app(DecideErasure::class)->approve($this->staff, $request->public_id, null);

        return DB::table('privacy_requests')->where('id', $request->id)->first();
    };
    $this->money = fn (int $userId) => DB::table('orders')->where('user_id', $userId)->orderBy('id')->get(['order_number', 'placed_at', 'status', 'payment_status', 'currency', 'subtotal_minor', 'discount_minor', 'shipping_minor', 'cod_fee_minor', 'tax_minor', 'grand_total_minor', 'payment_method_code'])->map(fn ($r) => (array) $r)->all();
    $this->items = fn (int $userId) => DB::table('order_items')->whereIn('order_id', DB::table('orders')->where('user_id', $userId)->pluck('id'))->orderBy('id')->get(['name_snapshot', 'sku_snapshot', 'quantity', 'unit_price_minor', 'tax_minor', 'line_total_minor'])->map(fn ($r) => (array) $r)->all();
});

describe('what an erased customer leaves behind', function () {
    it('keeps every amount, item and date of their orders, and nothing that says who they were', function () {
        // a second, physical order that was cancelled, so there is a delivery address to remove too
        $tee = ($this->place)([$this->tee => 1], $this->amina['user']->id, '+8801711111111', 'amina@example.com');
        app(TransitionOrder::class)->status(Actor::system('t'), $tee->id, OrderStatus::Cancelled, 'test');
        ($this->finish)($this->amina['order']->id);   // finished before the "before" snapshot: finishing changes the status
        $moneyBefore = ($this->money)($this->amina['user']->id);
        $itemsBefore = ($this->items)($this->amina['user']->id);
        expect(DB::table('order_addresses')->where('order_id', $tee->id)->value('address_line'))->not->toBe('');

        $request = ($this->erase)();

        expect($request->status)->toBe('done')->and(($this->money)($this->amina['user']->id))->toBe($moneyBefore)->and(($this->items)($this->amina['user']->id))->toBe($itemsBefore);
        $orders = DB::table('orders')->where('user_id', $this->amina['user']->id)->get();
        expect($orders)->toHaveCount(2);
        foreach ($orders as $order) {
            expect($order->contact_name)->toBe('Deleted customer')->and($order->contact_phone)->toBe('erased')->and($order->contact_email)->toBeNull()->and($order->anonymised_at)->not->toBeNull();
        }
        $address = DB::table('order_addresses')->where('order_id', $tee->id)->first();
        expect($address->recipient_name)->toBe('Deleted customer')->and($address->phone)->toBe('erased')->and($address->address_line)->toBe('')->and($address->area)->toBe('')->and($address->location_snapshot)->not->toBeNull();   // the district stays, the street goes
    });

    it('removes the sign-in, the profile, the saved addresses and the cart', function () {
        $id = $this->amina['user']->id;
        DB::table('carts')->insert(['public_id' => strtolower((string) Str::ulid()), 'user_id' => $id, 'currency' => 'BDT', 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('user_sessions')->insert(['user_id' => $id, 'session_id_hash' => str_repeat('a', 64), 'session_id_encrypted' => 'x', 'guard' => 'web', 'ip' => '203.0.113.9', 'user_agent' => 'Firefox', 'last_seen_at' => now(), 'created_at' => now()]);
        $this->amina['user']->createToken('phone app');
        DB::table('otp_challenges')->count();

        ($this->erase)();

        $user = DB::table('users')->where('id', $id)->first();
        expect($user->email)->toBe('erased-'.$this->amina['user']->public_id.'@erased.invalid')->and($user->name)->toBeNull()->and($user->phone)->toBeNull()->and($user->password)->toBeNull()->and($user->status)->toBe('disabled')->and($user->email_verified_at)->toBeNull();
        $customer = DB::table('customers')->where('user_id', $id)->first();
        expect($customer->name)->toBeNull()->and($customer->email)->toBeNull()->and($customer->phone)->toBeNull()->and($customer->birth_date)->toBeNull()->and($customer->anonymised_at)->not->toBeNull()
            ->and(DB::table('customer_addresses')->where('customer_id', $this->amina['customerId'])->count())->toBe(0)
            ->and(DB::table('carts')->where('user_id', $id)->count())->toBe(0)
            ->and(DB::table('user_sessions')->where('user_id', $id)->count())->toBe(0)
            ->and(DB::table('personal_access_tokens')->where('tokenable_id', $id)->count())->toBe(0);
    });

    it('can no longer sign in, by password or by phone', function () {
        ($this->erase)();

        $this->post('/en/login', ['identifier' => 'amina@example.com', 'password' => GOOD_PASSWORD])->assertSessionHasErrors();
        $this->post('/en/login', ['identifier' => 'erased-'.$this->amina['user']->public_id.'@erased.invalid', 'password' => GOOD_PASSWORD])->assertSessionHasErrors();
        expect(auth('web')->check())->toBeFalse();
    });

    it('drops their message choices and unsent messages, but keeps the proof of what they once agreed to', function () {
        $id = $this->amina['user']->id;
        DB::table('notification_logs')->insert(['public_id' => strtolower((string) Str::ulid()), 'idempotency_key' => 'k-'.Str::random(8), 'template_code' => 'order.shipped.sms', 'channel' => 'sms', 'kind' => 'transactional', 'locale' => 'en', 'recipient_masked' => '+88017****1111',
            'user_id' => $id, 'status' => 'queued', 'payload' => 'encrypted-address-and-values', 'created_at' => now(), 'updated_at' => now()]);

        ($this->erase)();

        $log = DB::table('notification_logs')->where('user_id', $id)->where('template_code', 'order.shipped.sms')->first();
        expect(DB::table('notification_preferences')->where('user_id', $id)->count())->toBe(0)->and($log->status)->toBe('skipped')->and($log->skip_reason)->toBe('erased')->and($log->payload)->toBeNull()
            ->and(DB::table('notification_consent_events')->where('user_id', $id)->count())->toBe(2);   // identifies nobody now, and is the proof
    });

    it('clears the address and browser from the download history but keeps the history', function () {
        $right = $this->amina['right'];
        DB::table('download_logs')->insert(['entitlement_id' => $right->id, 'user_id' => $this->amina['user']->id, 'outcome' => 'not_owner', 'ip' => '203.0.113.9', 'user_agent' => 'Firefox', 'created_at' => now()]);
        DB::table('download_logs')->insert(['entitlement_id' => $this->bob['right']->id, 'user_id' => $this->bob['user']->id, 'outcome' => 'not_owner', 'ip' => '198.51.100.7', 'user_agent' => 'Chrome', 'created_at' => now()]);

        ($this->erase)();

        $mine = DB::table('download_logs')->where('user_id', $this->amina['user']->id)->first();
        expect($mine->ip)->toBeNull()->and($mine->user_agent)->toBeNull()->and($mine->outcome)->toBe('not_owner')
            ->and(DB::table('download_logs')->where('user_id', $this->bob['user']->id)->value('ip'))->toBe('198.51.100.7');
    });

    it('deletes their earlier data copies', function () {
        ($this->as)($this->amina['user'])->post('/en/account/privacy/export');
        expect(Storage::disk('private')->allFiles('privacy'))->toHaveCount(1);

        ($this->erase)();

        expect(Storage::disk('private')->allFiles('privacy'))->toBe([])->and(DB::table('privacy_requests')->where('user_id', $this->amina['user']->id)->where('type', 'export')->value('status'))->toBe('expired');
    });

    it('tells them on the contact they had, once, and records who decided', function () {
        $request = ($this->erase)();

        Mail::assertSent(Mailable::class, fn ($m) => $m->hasTo('amina@example.com') && str_contains($m->text, 'has been erased'));
        expect(collect($this->sms->sent())->where('to', '+8801711111111')->pluck('message')->join(' '))->toContain('erased')
            ->and($request->decided_by)->toBe($this->manager->id)
            ->and(DB::table('notification_logs')->where('template_code', 'like', 'privacy.erasure_done%')->count())->toBe(2)
            ->and(DB::table('notification_logs')->where('template_code', 'like', 'privacy.erasure_done%')->whereNotNull('user_id')->count())->toBe(0);   // the account is gone: no link to it
    });

    it('touches nobody else', function () {
        $bob = ['user' => DB::table('users')->where('id', $this->bob['user']->id)->first(), 'orders' => ($this->money)($this->bob['user']->id), 'contact' => DB::table('orders')->where('user_id', $this->bob['user']->id)->value('contact_name'),
            'addresses' => DB::table('customer_addresses')->where('customer_id', $this->bob['customerId'])->count(), 'prefs' => DB::table('notification_preferences')->where('user_id', $this->bob['user']->id)->count()];

        ($this->erase)();

        expect(DB::table('users')->where('id', $this->bob['user']->id)->first())->toEqual($bob['user'])->and(($this->money)($this->bob['user']->id))->toBe($bob['orders'])
            ->and(DB::table('orders')->where('user_id', $this->bob['user']->id)->value('contact_name'))->toBe($bob['contact'])->and($bob['contact'])->toBe('Rahim')
            ->and(DB::table('customer_addresses')->where('customer_id', $this->bob['customerId'])->count())->toBe($bob['addresses'])->and(DB::table('notification_preferences')->where('user_id', $this->bob['user']->id)->count())->toBe($bob['prefs']);
    });

    it('can be run again without harm', function () {
        $request = ($this->erase)();
        $before = [DB::table('users')->where('id', $this->amina['user']->id)->first(), DB::table('orders')->where('user_id', $this->amina['user']->id)->first()];

        app(RunErasure::class)->handle($request->id);   // already done: nothing happens
        foreach (app(PersonalDataCatalog::class)->erasers() as $eraser) {
            $eraser->erase($this->amina['user']->id);   // every part is safe to repeat
        }

        expect(DB::table('users')->where('id', $this->amina['user']->id)->first())->toEqual($before[0])->and(DB::table('orders')->where('user_id', $this->amina['user']->id)->first())->toEqual($before[1])
            ->and(DB::table('audit_events')->where('action', 'privacy.erased')->count())->toBe(1);
    });
});

describe('when something happens between approving and erasing', function () {
    it('erases nothing if a new order is open by then, and goes ahead once it is over', function () {
        ($this->finish)($this->amina['order']->id);
        $request = app(RequestErasure::class)->handle($this->amina['user']->id, null, 'en');
        DB::table('privacy_requests')->where('id', $request->id)->update(['status' => 'approved']);   // approved, the job has not run yet
        $late = ($this->place)([$this->tee => 1], $this->amina['user']->id, '+8801711111111', 'amina@example.com');   // a new order arrives

        app(RunErasure::class)->handle($request->id);

        $row = DB::table('privacy_requests')->where('id', $request->id)->first();
        expect($row->status)->toBe('blocked')->and($row->failure)->toBe('orders.open')->and(DB::table('users')->where('id', $this->amina['user']->id)->value('email'))->toBe('amina@example.com');

        app(TransitionOrder::class)->status(Actor::system('t'), $late->id, OrderStatus::Cancelled, 'test');
        app(DecideErasure::class)->approve($this->staff, $request->public_id, null);
        expect(DB::table('privacy_requests')->where('id', $request->id)->value('status'))->toBe('done');
    });

    it('carries on where it stopped when one part fails, without repeating or skipping any', function () {
        $flaky = new class implements PersonalDataEraser
        {
            public static int $tries = 0;

            public function section(): string
            {
                return 'flaky';
            }

            public function blockers(int $userId): array
            {
                return [];
            }

            public function erase(int $userId): void
            {
                if (++self::$tries === 1) {
                    throw new RuntimeException('disk full while handling +8801711111111');
                }
            }
        };
        $flaky::$tries = 0;
        app(PersonalDataCatalog::class)->addEraser($flaky::class, 70);
        app()->bind($flaky::class, fn () => $flaky);
        ($this->finish)($this->amina['order']->id);
        $request = app(RequestErasure::class)->handle($this->amina['user']->id, null, 'en');
        DB::table('privacy_requests')->where('id', $request->id)->update(['status' => 'approved']);

        expect(fn () => app(RunErasure::class)->handle($request->id))->toThrow(RuntimeException::class);
        $row = DB::table('privacy_requests')->where('id', $request->id)->first();
        expect($row->status)->toBe('approved')->and($row->failure)->toBe('RuntimeException')->and(DB::table('orders')->where('user_id', $this->amina['user']->id)->value('contact_name'))->toBe('Deleted customer')   // the parts before it are done
            ->and(DB::table('users')->where('id', $this->amina['user']->id)->value('status'))->toBe('active');   // the account is last, so the customer can still be found

        app(RunErasure::class)->handle($request->id);   // the queue's second try
        expect(DB::table('privacy_requests')->where('id', $request->id)->first())->status->toBe('done')->failure->toBeNull();
        expect(DB::table('users')->where('id', $this->amina['user']->id)->value('status'))->toBe('disabled');
        expect(json_encode(DB::table('audit_events')->where('action', 'privacy.erasure_failed')->get()))->not->toContain('+8801711111111');   // the audit trail names the problem, never the details
    });
});

describe('what the database itself refuses', function () {
    it('lets an order\'s contact be replaced only by the erased placeholders, once, and nothing else about it ever', function () {
        $id = $this->amina['order']->id;
        $change = fn (array $set) => fn () => DB::table('orders')->where('id', $id)->update($set);

        $placeholders = ['contact_name' => 'Deleted customer', 'contact_phone' => 'erased', 'contact_email' => null];

        expect($change(['contact_name' => 'Someone else']))->toThrow(QueryException::class)
            ->and($change(['grand_total_minor' => 1, 'subtotal_minor' => 1]))->toThrow(QueryException::class)
            ->and($change($placeholders))->toThrow(QueryException::class)   // without the stamp
            ->and($change(['anonymised_at' => now()]))->toThrow(QueryException::class)   // the stamp without the placeholders
            ->and($change(array_merge($placeholders, ['contact_phone' => '+8801999999999', 'anonymised_at' => now()])))->toThrow(QueryException::class)   // not the placeholder
            ->and($change(array_merge($placeholders, ['anonymised_at' => now()])))->not->toThrow(QueryException::class);

        expect($change(['anonymised_at' => now()->addDay()]))->toThrow(QueryException::class)   // set once
            ->and($change(['contact_name' => 'Back again']))->toThrow(QueryException::class)
            ->and($change(['contact_email' => 'back@example.com']))->toThrow(QueryException::class)
            ->and(fn () => DB::table('orders')->where('id', $id)->delete())->toThrow(QueryException::class);
        expect(DB::table('orders')->where('id', $id)->first())->contact_name->toBe('Deleted customer');
    });

    it('lets a download record lose its address and browser, and nothing else', function () {
        DB::table('download_logs')->insert(['entitlement_id' => $this->amina['right']->id, 'user_id' => $this->amina['user']->id, 'outcome' => 'not_owner', 'ip' => '203.0.113.9', 'user_agent' => 'Firefox', 'created_at' => now()]);
        $log = fn (array $set) => fn () => DB::table('download_logs')->where('user_id', $this->amina['user']->id)->update($set);

        expect($log(['ip' => '10.0.0.1']))->toThrow(QueryException::class)->and($log(['user_agent' => 'Chrome']))->toThrow(QueryException::class)->and($log(['outcome' => 'revoked']))->toThrow(QueryException::class)
            ->and($log(['user_id' => null]))->toThrow(QueryException::class)->and(fn () => DB::table('download_logs')->where('user_id', $this->amina['user']->id)->delete())->toThrow(QueryException::class)
            ->and($log(['ip' => null, 'user_agent' => null]))->not->toThrow(QueryException::class);
    });

    it('only allows the moves a privacy request can make, and a status that fits its kind', function () {
        $now = now();
        $make = fn (string $type, string $status) => DB::table('privacy_requests')->insertGetId(['public_id' => strtolower((string) Str::ulid()), 'user_id' => $this->bob['user']->id, 'type' => $type, 'status' => $status, 'requested_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        $move = fn (int $id, string $to) => fn () => DB::table('privacy_requests')->where('id', $id)->update(['status' => $to]);

        $erasure = $make('erasure', 'requested');
        expect($move($erasure, 'done'))->toThrow(QueryException::class)->and($move($erasure, 'ready'))->toThrow(QueryException::class)   // done only after approval; "ready" is for copies
            ->and($move($erasure, 'approved'))->not->toThrow(QueryException::class)->and($move($erasure, 'requested'))->toThrow(QueryException::class)->and($move($erasure, 'done'))->not->toThrow(QueryException::class)
            ->and($move($erasure, 'approved'))->toThrow(QueryException::class);
        $export = $make('export', 'ready');
        expect($move($export, 'requested'))->toThrow(QueryException::class)->and($move($export, 'expired'))->not->toThrow(QueryException::class)->and($move($export, 'ready'))->toThrow(QueryException::class);
        expect(fn () => $make('export', 'approved'))->toThrow(QueryException::class)->and(fn () => $make('erasure', 'ready'))->toThrow(QueryException::class);
    });
});
