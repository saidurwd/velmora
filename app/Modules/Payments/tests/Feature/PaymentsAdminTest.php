<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\Orders\DTOs\OrderPaymentRequest;
use App\Modules\Payments\Actions\InitiatePayment;
use App\Modules\Payments\Actions\RecordCodCollection;
use App\Modules\Payments\Actions\VerifyPayment;
use App\Modules\Payments\Database\Seeders\PaymentMethodSeeder;
use App\Modules\Payments\DTOs\Capabilities;
use App\Modules\Payments\DTOs\VerifiedPayment;
use App\Modules\Payments\Enums\VerifiedStatus;
use App\Modules\Payments\Gateways\FakeGateway;
use App\Modules\Payments\Services\DatabasePaymentMethodCatalog;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed([LanguageSeeder::class, PaymentMethodSeeder::class, RolesAndPermissionsSeeder::class]);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    DB::table('payment_methods')->where('code', 'online')->update(['provider' => 'fake', 'is_enabled' => true]);
    $this->gateway = app(FakeGateway::class)->withCapabilities(new Capabilities(refund: true, partialRefund: true, refundStatus: true, statusQuery: true, sandbox: true, minAmountMinor: 1000, maxAmountMinor: 50_000_000));

    $this->finance = catalogStaff(['payments.view', 'payments.refund', 'payments.reconcile', 'settings.view', 'settings.manage', 'orders.view']);
    $this->second = catalogStaff(['payments.view', 'payments.refund']);
    $this->viewer = catalogStaff(['payments.view']);
    $this->nobody = catalogStaff(['catalog.view']);
    $this->as = fn ($who = null) => $this->actingAs($who ?? $this->finance, 'admin');

    $this->started = function (int $id, int $minor = 150000): string {
        fakeOrders([$id], ['payment_method_code' => 'online', 'subtotal_minor' => $minor, 'grand_total_minor' => $minor]);
        app(InitiatePayment::class)->handle(new OrderPaymentRequest($id, (string) DB::table('orders')->where('id', $id)->value('public_id'), sprintf('VM-TEST-%06d', $id), $minor, 'BDT', 'online', 'Rahim', '+8801712345678', null, 'House 5', 'Dhaka', '1205', 2, true, 'Tee', 'en'));

        return sprintf('VM-TEST-%06d-1', $id);
    };
    $this->answerPaid = fn (string $ref, int $minor = 150000) => $this->gateway->answer($ref, new VerifiedPayment(VerifiedStatus::Valid, $ref, Money::of($minor), 'BANK-'.$ref, 'VAL-'.$ref));
    $this->paid = function (int $id, int $minor = 150000): object {
        $ref = ($this->started)($id, $minor);
        ($this->answerPaid)($ref, $minor);
        app(VerifyPayment::class)->handle($ref);

        return DB::table('payments')->where('order_id', $id)->first();
    };
    $this->cod = function (int $id, int $minor = 120000): string {
        fakeOrders([$id], ['payment_method_code' => 'cod', 'status' => 'confirmed', 'subtotal_minor' => $minor, 'grand_total_minor' => $minor, 'created_at' => now()->subDays(2), 'placed_at' => now()->subDays(2)]);

        return sprintf('VM-TEST-%06d', $id);
    };
    $this->csv = fn (array $rows) => "reference,bank_txn_id,amount,currency,settled_at\n".implode("\n", array_map(fn (array $r) => implode(',', $r), $rows));
});

describe('who can see and do what', function () {
    $pages = ['/admin/payments/today', '/admin/payments', '/admin/payments/refunds', '/admin/payments/cod', '/admin/payments/reconciliation', '/admin/payments/settings'];

    it('sends guests to the sign-in page', function () use ($pages) {
        foreach ($pages as $page) {
            $this->get($page)->assertRedirect();
        }
    });

    it('keeps every page away from staff without payments.view', function () use ($pages) {
        foreach ($pages as $page) {
            ($this->as)($this->nobody)->get($page)->assertForbidden();
        }
    });

    it('lets a viewer look at every page', function () use ($pages) {
        foreach ($pages as $page) {
            ($this->as)($this->viewer)->get($page)->assertOk();
        }
    });

    it('keeps every action away from a viewer', function () {
        $p = ($this->paid)(1);
        $posts = [
            "/admin/payments/{$p->public_id}/verify" => [], "/admin/payments/{$p->public_id}/refunds" => ['amount' => '10', 'reason' => 'x'],
            '/admin/payments/refunds/01HZZZZZZZZZZZZZZZZZZZZZZZ/approve' => [], '/admin/payments/refunds/01HZZZZZZZZZZZZZZZZZZZZZZZ/reject' => ['reason' => 'x'],
            '/admin/payments/refunds/01HZZZZZZZZZZZZZZZZZZZZZZZ/send' => [], '/admin/payments/refunds/01HZZZZZZZZZZZZZZZZZZZZZZZ/complete' => ['proof' => 'x'],
            '/admin/payments/cod/collect' => ['order_number' => 'VM-1'], '/admin/payments/cod/remit' => [], '/admin/payments/reconciliation/import' => [],
            '/admin/payments/reconciliation/01HZZZZZZZZZZZZZZZZZZZZZZZ/resolve' => ['outcome' => 'resolved', 'note' => 'x'], '/admin/payments/settings/freeze' => ['frozen' => 1],
        ];
        foreach ($posts as $url => $data) {
            ($this->as)($this->viewer)->post($url, $data)->assertForbidden();
        }
        ($this->as)($this->viewer)->put('/admin/payments/settings', ['refund_limit' => '1'])->assertForbidden();
    });

    it('lets refund staff handle refunds but not cash, reconciliation, verify, freeze or settings', function () {
        $p = ($this->paid)(1);
        ($this->as)($this->second)->post("/admin/payments/{$p->public_id}/refunds", ['amount' => '100', 'reason' => 'Returned'])->assertRedirect()->assertSessionHas('status');
        ($this->as)($this->second)->post("/admin/payments/{$p->public_id}/verify")->assertForbidden();
        ($this->as)($this->second)->post('/admin/payments/cod/collect', ['order_number' => 'VM-1'])->assertForbidden();
        ($this->as)($this->second)->post('/admin/payments/settings/freeze', ['frozen' => 1])->assertForbidden();
        ($this->as)($this->second)->put('/admin/payments/settings', ['refund_limit' => '1'])->assertForbidden();
    });

    it('hides the forms from people who cannot use them', function () {
        $p = ($this->paid)(1);
        ($this->as)($this->viewer)->get("/admin/payments/{$p->public_id}")->assertOk()->assertDontSee('Ask for refund')->assertDontSee('Ask the payment service again');
        ($this->as)($this->viewer)->get('/admin/payments/cod')->assertOk()->assertDontSee('Record cash');
        ($this->as)($this->viewer)->get('/admin/payments/reconciliation')->assertOk()->assertDontSee('Check file');
        ($this->as)($this->finance)->get("/admin/payments/{$p->public_id}")->assertOk()->assertSee('Ask for refund')->assertSee('Ask the payment service again');
    });
});

describe('pages in both languages', function () {
    it('shows every page in English and in Bangla', function () {
        $p = ($this->paid)(1);
        $pages = ['/admin/payments/today' => ['Money today', 'আজকের টাকা'], '/admin/payments' => ['Payments', 'পেমেন্ট'], "/admin/payments/{$p->public_id}" => ['Attempts', 'চেষ্টাসমূহ'], '/admin/payments/refunds' => ['Refunds to handle', 'যেসব ফেরত দেখতে হবে'],
            '/admin/payments/cod' => ['Cash on delivery', 'ক্যাশ অন ডেলিভারি'], '/admin/payments/reconciliation' => ['Reconciliation', 'হিসাব মেলানো'], '/admin/payments/settings' => ['Payment settings', 'পেমেন্ট সেটিংস']];
        foreach ($pages as $url => [$en, $bn]) {
            ($this->as)()->get($url)->assertOk()->assertSee($en);
        }
        $this->finance->forceFill(['locale' => 'bn'])->save();   // in the tests the signed-in staff member's own language wins over the cookie
        foreach ($pages as $url => [$en, $bn]) {
            ($this->as)()->get($url)->assertOk()->assertSee($bn);
        }
    });
});

describe('the payment list and page', function () {
    it('finds a payment by order number, payment id, our reference or the bank id, and filters by state', function () {
        $a = ($this->paid)(1);
        $b = ($this->paid)(2);
        ($this->started)(3);

        ($this->as)()->get('/admin/payments?q=VM-TEST-000002')->assertOk()->assertSee(strtoupper(substr($b->public_id, -8)))->assertDontSee(strtoupper(substr($a->public_id, -8)));
        ($this->as)()->get('/admin/payments?q='.$a->public_id)->assertOk()->assertSee(strtoupper(substr($a->public_id, -8)))->assertDontSee(strtoupper(substr($b->public_id, -8)));
        ($this->as)()->get('/admin/payments?q=VM-TEST-000002-1')->assertOk()->assertSee(strtoupper(substr($b->public_id, -8)));
        ($this->as)()->get('/admin/payments?q=BANK-VM-TEST-000001-1')->assertOk()->assertSee(strtoupper(substr($a->public_id, -8)));
        ($this->as)()->get('/admin/payments?status=pending')->assertOk()->assertDontSee(strtoupper(substr($a->public_id, -8)));
        ($this->as)()->get('/admin/payments?status=bogus')->assertSessionHasErrors('status');
    });

    it('does not run more queries for a longer list', function () {
        ($this->paid)(1);
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            ($this->as)()->get('/admin/payments')->assertOk();

            return count(DB::getQueryLog());
        };
        $count();
        $few = $count();
        foreach (range(2, 12) as $id) {
            ($this->paid)($id);
        }

        expect($count())->toBe($few);
    });

    it('shows the raw notifications with secrets, card numbers and phone numbers hidden, and never changes the stored copy', function () {
        $p = ($this->paid)(1);
        $payload = ['tran_id' => 'VM-TEST-000001-1', 'status' => 'VALID', 'store_passwd' => 'SECRET-PASSWORD-1', 'card_no' => '4111111111111111', 'verify_sign' => 'abc123sig', 'cus_phone' => '01712345678', 'amount' => '1500.00'];
        DB::table('payment_webhook_events')->insert(['provider' => 'fake', 'provider_event_id' => 'evt-1', 'payment_id' => $p->id, 'payload' => json_encode($payload), 'status' => 'processed', 'received_at' => now()]);

        $html = ($this->as)()->get("/admin/payments/{$p->public_id}")->assertOk()->assertSee('VM-TEST-000001-1')->assertSee('1500.00')->getContent();

        expect($html)->not->toContain('SECRET-PASSWORD-1')->not->toContain('4111111111111111')->not->toContain('abc123sig')->not->toContain('01712345678')
            ->and(json_decode((string) DB::table('payment_webhook_events')->value('payload'), true))->toBe($payload);
    });

    it('asks the payment service again and reports what came of it', function () {
        $ref = ($this->started)(1);
        $payment = DB::table('payments')->first();
        ($this->answerPaid)($ref);

        ($this->as)()->post("/admin/payments/{$payment->public_id}/verify")->assertRedirect()->assertSessionHas('status');

        expect(DB::table('payments')->value('status'))->toBe('paid')->and(DB::table('audit_events')->where('action', 'payment.manual_verify')->count())->toBe(1);
    });

    it('does not offer to ask the service about cash', function () {
        $order = ($this->cod)(1);
        app(RecordCodCollection::class)->handle(new Actor(ActorType::Staff, $this->finance->id, null, 'x'), (string) DB::table('orders')->value('public_id'));
        $payment = DB::table('payments')->first();

        ($this->as)()->get("/admin/payments/{$payment->public_id}")->assertOk()->assertDontSee('Ask the payment service again');
        ($this->as)()->post("/admin/payments/{$payment->public_id}/verify")->assertSessionHasErrors('verify');
        expect($order)->not->toBeEmpty();
    });
});

describe('refunds through the screens', function () {
    it('goes from request to approval to sending, with a second person above the limit', function () {
        $p = ($this->paid)(1);
        DB::table('settings')->updateOrInsert(['key' => 'payments.refund_second_approver_minor'], ['value' => json_encode(50000), 'scope' => 'app', 'is_secret' => false, 'updated_at' => now(), 'created_at' => now()]);
        app(Settings::class)->set('payments.refund_second_approver_minor', 50000);

        ($this->as)()->post("/admin/payments/{$p->public_id}/refunds", ['amount' => '1500.00', 'reason' => 'Customer returned the item'])->assertRedirect()->assertSessionHas('status');
        $refund = DB::table('refunds')->first();
        expect($refund->amount_minor)->toBe(150000)->and($refund->status)->toBe('requested');
        ($this->as)()->get('/admin/payments/refunds')->assertOk()->assertSee('Customer returned the item')->assertSee('Approve');

        ($this->as)()->post("/admin/payments/refunds/{$refund->public_id}/approve")->assertSessionHasErrors('refund');   // the same person cannot approve a large refund
        expect(DB::table('refunds')->value('status'))->toBe('requested');

        ($this->as)($this->second)->post("/admin/payments/refunds/{$refund->public_id}/approve")->assertSessionHas('status');
        ($this->as)($this->second)->get('/admin/payments/refunds')->assertOk()->assertSee('Send to the payment service');
        ($this->as)($this->second)->post("/admin/payments/refunds/{$refund->public_id}/send")->assertSessionHas('status');

        expect(DB::table('refunds')->value('status'))->toBe('processing')->and($this->gateway->refunds)->toHaveCount(1);
    });

    it('records a refund paid back by hand, with proof, and the order follows', function () {
        $p = ($this->paid)(1);
        ($this->as)()->post("/admin/payments/{$p->public_id}/refunds", ['amount' => '100', 'reason' => 'Goodwill', 'manual' => '1'])->assertSessionHas('status');
        $refund = DB::table('refunds')->first();
        ($this->as)($this->second)->post("/admin/payments/refunds/{$refund->public_id}/approve")->assertSessionHas('status');

        ($this->as)($this->second)->post("/admin/payments/refunds/{$refund->public_id}/complete", ['proof' => ''])->assertSessionHasErrors('proof');
        ($this->as)($this->second)->post("/admin/payments/refunds/{$refund->public_id}/complete", ['proof' => 'bKash TrxID 9A8B7C'])->assertSessionHas('status');

        expect(DB::table('refunds')->value('status'))->toBe('succeeded')->and(DB::table('refunds')->value('proof_ref'))->toBe('bKash TrxID 9A8B7C')->and(DB::table('orders')->value('payment_status'))->toBe('partially_refunded');
    });

    it('rejects with a reason, and shows the reason of a refusal in plain words', function () {
        $p = ($this->paid)(1);
        ($this->as)()->post("/admin/payments/{$p->public_id}/refunds", ['amount' => '99999.00', 'reason' => 'Too much'])->assertSessionHasErrors('refund');
        ($this->as)()->post("/admin/payments/{$p->public_id}/refunds", ['amount' => '10', 'reason' => 'x'])->assertSessionHas('status');
        $refund = DB::table('refunds')->first();

        ($this->as)($this->second)->post("/admin/payments/refunds/{$refund->public_id}/reject", ['reason' => ''])->assertSessionHasErrors('reason');
        ($this->as)($this->second)->post("/admin/payments/refunds/{$refund->public_id}/reject", ['reason' => 'Not eligible'])->assertSessionHas('status');

        expect(DB::table('refunds')->value('status'))->toBe('rejected')->and((int) DB::table('payments')->value('refunded_minor'))->toBe(0);
    });

    it('reads the amount exactly, including Bangla digits, and refuses anything else', function () {
        $p = ($this->paid)(1);
        ($this->as)()->post("/admin/payments/{$p->public_id}/refunds", ['amount' => '১২০.৫০', 'reason' => 'Bangla digits'])->assertSessionHas('status');
        expect((int) DB::table('refunds')->value('amount_minor'))->toBe(12050);

        foreach (['abc', '1.234', '-5', '1e3', '', '0'] as $bad) {
            ($this->as)()->post("/admin/payments/{$p->public_id}/refunds", ['amount' => $bad, 'reason' => 'x'])->assertSessionHasErrors();
        }
        expect(DB::table('refunds')->count())->toBe(1);
    });

    it('escapes what staff type', function () {
        $p = ($this->paid)(1);
        ($this->as)()->post("/admin/payments/{$p->public_id}/refunds", ['amount' => '10', 'reason' => '<script>alert(1)</script>'])->assertSessionHas('status');

        ($this->as)()->get('/admin/payments/refunds')->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
    });
});

describe('cash on delivery through the screens', function () {
    it('records the cash by order number, once', function () {
        $number = ($this->cod)(1);

        ($this->as)()->post('/admin/payments/cod/collect', ['order_number' => strtolower($number), 'note' => 'Rider Karim'])->assertRedirect()->assertSessionHas('status');
        ($this->as)()->post('/admin/payments/cod/collect', ['order_number' => $number])->assertSessionHasErrors('collect');
        ($this->as)()->post('/admin/payments/cod/collect', ['order_number' => 'VM-NOPE'])->assertSessionHasErrors('collect');

        expect(DB::table('cod_collections')->count())->toBe(1)->and(DB::table('orders')->value('payment_status'))->toBe('paid');
        ($this->as)()->get('/admin/payments/cod')->assertOk()->assertSee($number);
    });

    it('enters a courier statement from the list of collected cash, and a short one shows up as a reconciliation item', function () {
        foreach ([1, 2] as $id) {
            ($this->as)()->post('/admin/payments/cod/collect', ['order_number' => ($this->cod)($id, $id * 50000)]);
        }
        $parcels = DB::table('cod_collections')->orderBy('id')->pluck('public_id')->all();

        ($this->as)()->post('/admin/payments/cod/remit', ['courier' => 'Pathao', 'statement' => 'ST-77', 'received' => '1400.00', 'parcels' => $parcels, 'amounts' => [$parcels[0] => '500.00', $parcels[1] => '1000.00']])->assertSessionHas('status');

        $remittance = DB::table('cod_remittances')->first();
        expect($remittance->status)->toBe('short')->and($remittance->expected_minor)->toBe(150000);
        ($this->as)()->get('/admin/payments/reconciliation')->assertOk()->assertSee('Courier paid too little');
        ($this->as)()->get('/admin/payments/today')->assertOk()->assertSee('Courier paid too little');
    });

    it('refuses a statement with no parcels or a bad amount', function () {
        ($this->as)()->post('/admin/payments/cod/remit', ['courier' => 'Pathao', 'statement' => 'ST-1', 'received' => '10', 'parcels' => [], 'amounts' => []])->assertSessionHasErrors('parcels');
        ($this->as)()->post('/admin/payments/cod/remit', ['courier' => 'Pathao', 'statement' => 'ST-1', 'received' => 'ten', 'parcels' => ['01HZZZZZZZZZZZZZZZZZZZZZZZ'], 'amounts' => ['01HZZZZZZZZZZZZZZZZZZZZZZZ' => '5']])->assertSessionHasErrors('received');
    });
});

describe('reconciliation through the screens', function () {
    it('checks an uploaded file, lists what does not match, and lets staff close the item with a note', function () {
        $p = ($this->paid)(1);
        $file = UploadedFile::fake()->createWithContent('settlement.csv', ($this->csv)([['VM-TEST-000001-1', 'BANK-VM-TEST-000001-1', '1499.00', 'BDT', '2026-10-31T10:00:00Z']]));

        ($this->as)()->post('/admin/payments/reconciliation/import', ['provider' => 'fake', 'from' => now()->subDay()->format('Y-m-d'), 'to' => now()->format('Y-m-d'), 'file' => $file])->assertRedirect()->assertSessionHas('status');

        ($this->as)()->get('/admin/payments/reconciliation')->assertOk()->assertSee('Amount differs')->assertSee('VM-TEST-000001-1');
        $exception = DB::table('reconciliation_exceptions')->first();
        ($this->as)()->post("/admin/payments/reconciliation/{$exception->public_id}/resolve", ['outcome' => 'resolved', 'note' => ''])->assertSessionHasErrors('note');
        ($this->as)()->post("/admin/payments/reconciliation/{$exception->public_id}/resolve", ['outcome' => 'resolved', 'note' => 'Provider keying error'])->assertSessionHas('status');
        ($this->as)()->post("/admin/payments/reconciliation/{$exception->public_id}/resolve", ['outcome' => 'ignored', 'note' => 'Again'])->assertSessionHasErrors('resolve');

        ($this->as)()->get('/admin/payments/reconciliation?status=resolved')->assertOk()->assertSee('Provider keying error');
        ($this->as)()->get('/admin/payments/reconciliation')->assertOk()->assertDontSee('Provider keying error');
        expect(DB::table('payments')->value('captured_minor'))->toBe(150000)->and($p->public_id)->not->toBeEmpty();
    });

    it('refuses files that are the wrong type, too big, not text, or the same file twice', function () {
        ($this->paid)(1);
        $form = fn ($file) => ['provider' => 'fake', 'from' => now()->subDay()->format('Y-m-d'), 'to' => now()->format('Y-m-d'), 'file' => $file];
        $csv = ($this->csv)([['VM-TEST-000001-1', 'BANK-VM-TEST-000001-1', '1500.00', 'BDT', '2026-10-31T10:00:00Z']]);

        ($this->as)()->post('/admin/payments/reconciliation/import', $form(UploadedFile::fake()->createWithContent('x.exe', $csv)))->assertSessionHasErrors('file');
        ($this->as)()->post('/admin/payments/reconciliation/import', $form(UploadedFile::fake()->create('big.csv', 2500)))->assertSessionHasErrors('file');
        ($this->as)()->post('/admin/payments/reconciliation/import', $form(UploadedFile::fake()->createWithContent('bin.csv', "\xff\xfe\x00\x01")))->assertSessionHasErrors('import');
        ($this->as)()->post('/admin/payments/reconciliation/import', $form(UploadedFile::fake()->createWithContent('ok.csv', $csv)))->assertSessionHas('status');
        ($this->as)()->post('/admin/payments/reconciliation/import', $form(UploadedFile::fake()->createWithContent('ok.csv', $csv)))->assertSessionHasErrors('import');

        expect(DB::table('settlement_imports')->count())->toBe(1);
    });

    it('refuses a bad period', function () {
        $csv = ($this->csv)([['A', 'B', '5.00', 'BDT', '2026-10-31T10:00:00Z']]);
        ($this->as)()->post('/admin/payments/reconciliation/import', ['provider' => 'fake', 'from' => now()->format('Y-m-d'), 'to' => now()->subDays(3)->format('Y-m-d'), 'file' => UploadedFile::fake()->createWithContent('a.csv', $csv)])->assertSessionHasErrors('to');
        ($this->as)()->post('/admin/payments/reconciliation/import', ['provider' => 'fake', 'from' => now()->subDays(60)->format('Y-m-d'), 'to' => now()->format('Y-m-d'), 'file' => UploadedFile::fake()->createWithContent('a.csv', $csv)])->assertSessionHasErrors('import');
    });
});

describe('settings and the freeze switch', function () {
    it('pauses and resumes online payments, which takes effect at checkout at once, and is audited', function () {
        $catalog = fn () => collect(app(DatabasePaymentMethodCatalog::class)->options(100000, ['physical'], 'en'))->pluck('code')->all();
        expect($catalog())->toContain('online');

        ($this->as)()->post('/admin/payments/settings/freeze', ['frozen' => 1])->assertSessionHas('status');
        expect($catalog())->not->toContain('online')->toContain('cod');
        ($this->as)()->get('/admin/payments/settings')->assertOk()->assertSee('Online payments are PAUSED.')->assertSee('Resume online payments');

        ($this->as)()->post('/admin/payments/settings/freeze', ['frozen' => 0])->assertSessionHas('status');
        expect($catalog())->toContain('online')->and(DB::table('audit_events')->where('action', 'payments.freeze')->count())->toBe(2);
    });

    it('changes the refund limit and the offered methods, and refuses an online method with no payment service behind it', function () {
        ($this->as)()->put('/admin/payments/settings', ['refund_limit' => '2000.00', 'methods' => ['online' => '0', 'cod' => '1']])->assertSessionHas('status');
        expect((int) app(Settings::class)->int('payments.refund_second_approver_minor'))->toBe(200000)->and((bool) DB::table('payment_methods')->where('code', 'online')->value('is_enabled'))->toBeFalse();

        DB::table('payment_methods')->where('code', 'online')->update(['provider' => 'nowhere']);
        ($this->as)()->put('/admin/payments/settings', ['refund_limit' => '2000.00', 'methods' => ['online' => '1']])->assertSessionHasErrors('settings');
        expect((bool) DB::table('payment_methods')->where('code', 'online')->value('is_enabled'))->toBeFalse();

        ($this->as)()->put('/admin/payments/settings', ['refund_limit' => '99999999999.00'])->assertSessionHasErrors();
        ($this->as)()->put('/admin/payments/settings', ['refund_limit' => 'lots'])->assertSessionHasErrors();
    });

    it('shows how each payment service is doing from our own records', function () {
        ($this->paid)(1);
        ($this->started)(2);
        DB::table('payment_webhook_events')->insert(['provider' => 'fake', 'provider_event_id' => 'e1', 'payload' => '{}', 'status' => 'error', 'received_at' => now()]);

        $page = ($this->as)()->get('/admin/payments/settings')->assertOk()->assertSee('Payment service health')->assertSee('fake')->assertSee('Test');

        expect($page->getContent())->not->toContain('store_passwd');
    });
});

describe('the finance page', function () {
    it('adds up today\'s money from the ledgers', function () {
        ($this->paid)(1);
        ($this->paid)(2, 80000);
        ($this->as)()->post('/admin/payments/cod/collect', ['order_number' => ($this->cod)(3, 50000)]);

        $page = ($this->as)()->get('/admin/payments/today')->assertOk();

        $page->assertSee('2,300.00')->assertSee('500.00')->assertSee('2 payments');
    });
});

describe('rate limits', function () {
    it('slows down uploads of settlement files, for that person only', function () {
        $form = fn () => ['provider' => 'fake', 'from' => now()->subDay()->format('Y-m-d'), 'to' => now()->format('Y-m-d'), 'file' => UploadedFile::fake()->createWithContent('a.csv', 'nonsense')];
        foreach (range(1, 10) as $i) {
            ($this->as)()->post('/admin/payments/reconciliation/import', $form())->assertStatus(302);
        }

        ($this->as)()->post('/admin/payments/reconciliation/import', $form())->assertStatus(429);
        $other = catalogStaff(['payments.view', 'payments.reconcile']);
        ($this->as)($other)->post('/admin/payments/reconciliation/import', $form())->assertStatus(302);
    });
});
