<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Reporting\Actions\BuildReportExport;
use App\Modules\Reporting\Actions\DownloadReportExport;
use App\Modules\Reporting\Actions\ExpireReportExports;
use App\Modules\Reporting\Actions\RequestReportExport;
use App\Modules\Reporting\DTOs\ReportColumn;
use App\Modules\Reporting\DTOs\ReportFilter;
use App\Modules\Reporting\Exceptions\ReportException;
use App\Modules\Reporting\Jobs\BuildReportExportJob;
use App\Modules\Reporting\Reports\ReportCatalogue;
use App\Modules\Reporting\Reports\ReportDefinition;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\PermissionRegistrar;

/* Report files: asking, building, keeping, downloading and expiring. Money-adjacent and personal data may sit in a file, so every rule here has a test. */

beforeEach(function () {
    shopWorld($this);
    reportWorld($this);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    Storage::fake('private');
    config(['reporting.exports.disk' => 'private']);
    $this->finance = catalogStaff(['reports.view', 'reports.export', 'reports.finance.view', 'inventory.view']);
    $this->analyst = catalogStaff(['reports.view', 'reports.export']);
    $this->viewer = catalogStaff(['reports.view']);
    $this->nobody = catalogStaff(['catalog.view']);
    $this->as = fn (User $u) => $this->flushSession()->actingAs($u, 'admin');
    ($this->scenario)();
    ($this->work)();
    $this->fields = ['from' => '2026-03-10', 'to' => '2026-03-11'];
    $this->ask = fn (User $u, string $code = 'sales_by_day', array $fields = []) => ($this->as)($u)->post("/admin/reports/{$code}/export", $fields + $this->fields);
    $this->exportOf = fn (?User $u = null) => DB::table('report_exports')->where('requested_by', ($u ?? $this->finance)->id)->orderByDesc('id')->first();
    $this->contents = fn (object $export): string => str_replace("\xEF\xBB\xBF", '', (string) Storage::disk('private')->get((string) $export->file_key));
    $this->actor = fn (User $u) => new Actor(ActorType::Staff, $u->id, $u->public_id, $u->name);
    $this->link = fn (object $export, ?int $minutes = null) => URL::temporarySignedRoute('admin.reports.exports.download', now()->addMinutes($minutes ?? 30), ['publicId' => $export->public_id]);

    /** A report with personal data and a hostile cell, only for these tests. */
    $this->pii = new class implements ReportDefinition
    {
        public function code(): string
        {
            return 'fixture_people';
        }

        public function permissions(): array
        {
            return ['reports.view'];
        }

        public function filters(): array
        {
            return ['period'];
        }

        public function columns(): array
        {
            return [new ReportColumn('name'), new ReportColumn('phone', pii: true), new ReportColumn('email', pii: true), new ReportColumn('balance', 'money')];
        }

        public function rows(ReportFilter $filter): iterable
        {
            yield ['name' => '=HYPERLINK("http://evil.example","click")', 'phone' => '+8801712345678', 'email' => 'amina@example.com', 'balance' => -1250];
            yield ['name' => 'Rahim, "the" Tailor', 'phone' => '01898765432', 'email' => 'rahim@example.com', 'balance' => 99];
        }
    };
    app(ReportCatalogue::class)->register($this->pii);
});

describe('asking', function () {
    it('records the request with what the file will hold, and builds it', function () {
        ($this->ask)($this->finance)->assertRedirect('/admin/reports/exports');

        $e = ($this->exportOf)();
        expect($e->report_code)->toBe('sales_by_day')->and($e->status)->toBe('ready')->and((bool) $e->with_finance)->toBeTrue()->and((bool) $e->with_pii)->toBeFalse()
            ->and(json_decode((string) $e->filters, true))->toBe(['from' => '2026-03-10', 'to' => '2026-03-11'])->and($e->locale)->toBe('en')->and($e->row_count)->toBe(2)
            ->and($e->file_key)->toStartWith('reports/exports/')->and($e->file_key)->toEndWith('.csv')->and(strlen((string) $e->public_id))->toBe(26)
            ->and(DB::table('audit_events')->where('action', 'reports.export_requested')->count())->toBe(1);
    });

    it('is only for staff who may export, and for reports they may open', function () {
        ($this->ask)($this->viewer)->assertForbidden();
        ($this->ask)($this->nobody)->assertForbidden();
        ($this->ask)($this->analyst, 'cod_aging')->assertForbidden();         // cash aging is finance only
        ($this->ask)($this->analyst, 'low_stock')->assertForbidden();         // stock needs inventory.view
        ($this->ask)($this->finance, 'nothing_here')->assertNotFound();
        auth('admin')->logout();
        $this->flushSession()->post('/admin/reports/sales_by_day/export', $this->fields)->assertRedirect('/admin/login');

        expect(DB::table('report_exports')->count())->toBe(0);
    });

    it('refuses a bad period and keeps no trace', function () {
        ($this->ask)($this->finance, 'sales_by_day', ['from' => '2026-03-11', 'to' => '2026-03-01'])->assertSessionHasErrors('to');
        ($this->ask)($this->finance, 'sales_by_day', ['from' => '2020-01-01', 'to' => '2026-03-01'])->assertSessionHasErrors('to');
        ($this->ask)($this->finance, 'sales_by_day', ['channel' => 'moon'])->assertSessionHasErrors('channel');

        expect(DB::table('report_exports')->count())->toBe(0);
    });

    it('drops choices the report does not have', function () {
        ($this->ask)($this->finance, 'cod_aging', ['channel' => 'web', 'method' => 'cod'])->assertRedirect();
        ($this->ask)($this->finance, 'channels_languages', ['channel' => 'web', 'method' => 'cod'])->assertRedirect();

        $filters = DB::table('report_exports')->orderBy('id')->pluck('filters')->map(fn ($f) => json_decode((string) $f, true))->all();
        expect($filters[0])->not->toHaveKey('channel')->not->toHaveKey('method')   // cod_aging has no choices at all
            ->and($filters[1])->toHaveKey('method')->not->toHaveKey('channel');    // channels_languages splits by channel itself, so it takes no channel choice
    });

    it('lets one person have only a few waiting at once', function () {
        Queue::fake();
        foreach (range(1, 3) as $i) {
            ($this->ask)($this->finance)->assertRedirect('/admin/reports/exports');
        }
        ($this->ask)($this->finance)->assertSessionHasErrors('export');
        ($this->ask)($this->analyst)->assertRedirect('/admin/reports/exports');   // somebody else is not held up

        expect(DB::table('report_exports')->where('requested_by', $this->finance->id)->count())->toBe(3)->and(DB::table('report_exports')->count())->toBe(4);
    });

    it('limits how often one person may ask', function () {
        config(['reporting.exports.open_max' => 100]);
        Queue::fake();
        foreach (range(1, 10) as $i) {
            ($this->ask)($this->finance)->assertRedirect();
        }

        ($this->ask)($this->finance)->assertStatus(429);
    });
});

describe('asking, in the action itself', function () {
    it('refuses a period that is too long or not a period, whoever calls it', function () {
        $request = app(RequestReportExport::class);
        $actor = ($this->actor)($this->finance);

        expect(fn () => $request->handle($actor, 'sales_by_day', new ReportFilter('2020-01-01', '2026-03-01'), true, false, 'en'))->toThrow(fn (ReportException $e) => $e->reason === 'period_too_long')
            ->and(fn () => $request->handle($actor, 'sales_by_day', new ReportFilter('2026-03-05', '2026-03-01'), true, false, 'en'))->toThrow(fn (ReportException $e) => $e->reason === 'invalid_period')
            ->and(fn () => $request->handle($actor, 'sales_by_day', new ReportFilter('soon', '2026-03-01'), true, false, 'en'))->toThrow(fn (ReportException $e) => $e->reason === 'invalid_period')
            ->and(fn () => $request->handle($actor, 'nothing_here', new ReportFilter('2026-03-01', '2026-03-02'), true, false, 'en'))->toThrow(fn (ReportException $e) => $e->reason === 'unknown_report');
        expect(DB::table('report_exports')->count())->toBe(0);
    });
});

describe('building', function () {
    it('writes a spreadsheet-ready file that equals the ledgers (finance columns for finance)', function () {
        ($this->ask)($this->finance);
        $e = ($this->exportOf)();
        $raw = (string) Storage::disk('private')->get((string) $e->file_key);
        $lines = preg_split('/\r?\n/', trim(($this->contents)($e)));

        expect(str_starts_with($raw, "\xEF\xBB\xBF"))->toBeTrue()   // the byte-order mark Excel needs for Bangla
            ->and($lines[0])->toBe('Day,Orders,"Gross sales",Discounts,"Delivery charges","Cash-on-delivery fees",Tax,"Billed total","Average order value","Cancelled orders","Money received","Money given back","Net sales"')
            ->and($lines[1])->toBe('2026-03-10,3,1700.00,100.00,120.00,20.00,26.08,1740.00,580.00,1,1260.00,0.00,1600.00')
            ->and($lines[2])->toBe('2026-03-11,0,0.00,0.00,0.00,0.00,0.00,0.00,0.00,0,580.00,500.00,-200.00')   // a negative number stays a number
            ->and($lines)->toHaveCount(3)->and((int) $e->size_bytes)->toBe(strlen($raw));
        // and the file's billed total is the ledger's
        $billed = array_sum(array_map(fn ($l) => (float) str_getcsv($l)[7], array_slice($lines, 1)));
        expect(round($billed * 100))->toBe((float) DB::table('orders')->where('status', '<>', 'cancelled')->sum('grand_total_minor'));
    });

    it('writes the headings in the language asked for even when the worker runs in another language', function () {
        Queue::fake();
        $this->finance->forceFill(['locale' => 'bn'])->save();
        ($this->ask)($this->finance);
        $id = (int) ($this->exportOf)()->id;

        app()->setLocale('en');   // a queue worker starts in the default language, not in the language of the request
        app(BuildReportExport::class)->handle($id);
        $e = ($this->exportOf)();

        expect(explode(',', ($this->contents)($e))[0])->toBe('দিন')->and(app()->getLocale())->toBe('en');   // and it puts the language back
    });

    it('leaves the finance columns out for someone without finance permission', function () {
        ($this->ask)($this->analyst);
        $e = ($this->exportOf)($this->analyst);
        $lines = preg_split('/\r?\n/', trim(($this->contents)($e)));

        expect((bool) $e->with_finance)->toBeFalse()->and(count(str_getcsv($lines[0])))->toBe(10)->and($lines[0])->not->toContain('Money received')->not->toContain('Net sales')
            ->and($lines[1])->toBe('2026-03-10,3,1700.00,100.00,120.00,20.00,26.08,1740.00,580.00,1');
    });

    it('writes the headings in the language of the person who asked', function () {
        $this->finance->forceFill(['locale' => 'bn'])->save();
        ($this->ask)($this->finance);
        $e = ($this->exportOf)();

        expect($e->locale)->toBe('bn')->and(explode(',', ($this->contents)($e))[0])->toBe('দিন')->and(($this->contents)($e))->toContain('মোট বিক্রয়');
    });

    it('neutralises formulas, quotes awkward text, and masks personal data unless the person may see it', function () {
        $masked = catalogStaff(['reports.view', 'reports.export']);
        $seeing = catalogStaff(['reports.view', 'reports.export', 'customers.view_pii']);
        ($this->ask)($masked, 'fixture_people');
        ($this->ask)($seeing, 'fixture_people');

        $a = preg_split('/\r?\n/', trim(($this->contents)(($this->exportOf)($masked))));
        $b = preg_split('/\r?\n/', trim(($this->contents)(($this->exportOf)($seeing))));

        expect($a[0])->toBe('name,phone,email,balance')
            ->and($a[1])->toBe('"\'=HYPERLINK(""http://evil.example"",""click"")",\'+880****678,a***@example.com,-12.50')   // the cell cannot run as a formula; the phone and email are masked (a leading + is text, too); the number is left alone
            ->and($a[2])->toBe('"Rahim, ""the"" Tailor",0189****432,r***@example.com,0.99')
            ->and($b[1])->toBe('"\'=HYPERLINK(""http://evil.example"",""click"")",\'+8801712345678,amina@example.com,-12.50')   // a phone number opening with + is text, not a formula
            ->and((bool) ($this->exportOf)($masked)->with_pii)->toBeFalse()->and((bool) ($this->exportOf)($seeing)->with_pii)->toBeTrue();
    });

    it('refuses a report with too many rows and leaves no file', function () {
        config(['reporting.exports.max_rows' => 2]);
        ($this->ask)($this->finance, 'sales_by_day', ['from' => '2026-03-01', 'to' => '2026-03-10']);
        $e = ($this->exportOf)();

        expect($e->status)->toBe('failed')->and($e->error_code)->toBe('too_many_rows')->and($e->file_key)->toBeNull()->and(Storage::disk('private')->allFiles())->toBe([]);
        ($this->as)($this->finance)->get('/admin/reports/exports')->assertOk()->assertSee('too many rows');
    });

    it('records a failure as a word, keeps no file, and writes the cause to the log only', function () {
        app(ReportCatalogue::class)->register(new class implements ReportDefinition
        {
            public function code(): string
            {
                return 'fixture_boom';
            }

            public function permissions(): array
            {
                return ['reports.view'];
            }

            public function filters(): array
            {
                return [];
            }

            public function columns(): array
            {
                return [new ReportColumn('x')];
            }

            public function rows(ReportFilter $filter): iterable
            {
                yield ['x' => 'one'];
                throw new RuntimeException('secret detail: password=hunter2');
            }
        });
        Log::spy();
        ($this->ask)($this->finance, 'fixture_boom');
        $e = ($this->exportOf)();

        expect($e->status)->toBe('failed')->and($e->error_code)->toBe('failed')->and($e->file_key)->toBeNull()->and(Storage::disk('private')->allFiles())->toBe([])
            ->and(json_encode($e))->not->toContain('hunter2');
        Log::shouldHaveReceived('warning')->with('reports.export_failed', Mockery::on(fn ($c) => ! str_contains(json_encode($c), 'hunter2') && $c['reason'] === 'failed'))->once();
    });

    it('builds a file only once, however often the build is run', function () {
        Queue::fake();
        ($this->ask)($this->finance);
        $id = (int) ($this->exportOf)()->id;
        Queue::assertPushed(BuildReportExportJob::class, 1);

        app(BuildReportExport::class)->handle($id);
        $first = ($this->exportOf)();
        app(BuildReportExport::class)->handle($id);
        app(BuildReportExport::class)->handle($id);

        expect(Storage::disk('private')->allFiles())->toHaveCount(1)->and(($this->exportOf)()->file_key)->toBe($first->file_key)->and(($this->exportOf)()->status)->toBe('ready');
    });

    it('lets the database refuse a status that goes backwards', function () {
        ($this->ask)($this->finance);
        $id = ($this->exportOf)()->id;

        expect(fn () => DB::table('report_exports')->where('id', $id)->update(['status' => 'running']))->toThrow(QueryException::class)
            ->and(fn () => DB::table('report_exports')->where('id', $id)->update(['status' => 'failed']))->toThrow(QueryException::class)
            ->and(fn () => DB::table('report_exports')->where('id', $id)->update(['status' => 'bogus']))->toThrow(QueryException::class);
    });
});

describe('downloading', function () {
    it('gives the file to the person who asked, with safe headers, and counts and audits it', function () {
        ($this->ask)($this->finance);
        $e = ($this->exportOf)();
        $response = ($this->as)($this->finance)->get(($this->link)($e))->assertOk();

        expect($response->headers->get('Content-Type'))->toBe('text/csv; charset=utf-8')->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')->and($response->headers->get('Content-Disposition'))->toContain('sales_by_day-2026-03-10-2026-03-11.csv')
            ->and($response->streamedContent())->toBe((string) Storage::disk('private')->get((string) $e->file_key));
        $after = ($this->exportOf)();
        expect((int) $after->downloads)->toBe(1)->and($after->last_downloaded_at)->not->toBeNull()
            ->and(DB::table('audit_events')->where('action', 'reports.export_downloaded')->count())->toBe(1);
    });

    it('shows a link on the list only for files that are ready, and only the person\'s own', function () {
        ($this->ask)($this->finance);
        ($this->ask)($this->analyst);
        $page = ($this->as)($this->finance)->get('/admin/reports/exports')->assertOk();
        $mine = ($this->exportOf)($this->finance);
        $theirs = ($this->exportOf)($this->analyst);

        $page->assertSee('Download')->assertSee('signature=', false)->assertSee($mine->public_id, false)->assertDontSee($theirs->public_id, false);
        expect($page->getContent())->not->toMatch('#/exports/\d+/download#');   // no plain numbers in an address
    });

    it('is a plain not found for somebody else\'s file, even with the same permissions', function () {
        ($this->ask)($this->finance);
        $mine = ($this->exportOf)();
        $colleague = catalogStaff(['reports.view', 'reports.export', 'reports.finance.view', 'inventory.view']);

        ($this->as)($colleague)->get(($this->link)($mine))->assertNotFound();
        ($this->as)($this->analyst)->get(($this->link)($mine))->assertNotFound();
        expect((int) ($this->exportOf)()->downloads)->toBe(0)->and(DB::table('audit_events')->where('action', 'reports.export_downloaded')->count())->toBe(0);
    });

    it('needs a valid signature, a signed-in person with the permission, and a link that has not run out', function () {
        ($this->ask)($this->finance);
        $e = ($this->exportOf)();
        $unsigned = url("/admin/reports/exports/{$e->public_id}/download");
        $tampered = str_replace('signature=', 'signature=0', ($this->link)($e));

        ($this->as)($this->finance)->get($unsigned)->assertForbidden();
        ($this->as)($this->finance)->get($tampered)->assertForbidden();
        $link = ($this->link)($e, 30);
        auth('admin')->logout();
        $this->flushSession()->get($link)->assertRedirect('/admin/login');   // a signature alone is not enough
        ($this->as)($this->viewer)->get($link)->assertForbidden();           // no export permission
        $this->travel(31)->minutes();
        ($this->as)($this->finance)->get($link)->assertForbidden();         // the link has run out (the file has not)

        expect((int) ($this->exportOf)()->downloads)->toBe(0);
    });

    it('refuses a file built with finance columns to someone who has since lost that right', function () {
        ($this->ask)($this->finance);
        $e = ($this->exportOf)();
        $this->finance->revokePermissionTo('reports.finance.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        ($this->as)($this->finance->fresh())->get(($this->link)($e))->assertForbidden();
        expect((int) ($this->exportOf)()->downloads)->toBe(0);
    });

    it('refuses a file with personal data to someone who has since lost that right', function () {
        $seeing = catalogStaff(['reports.view', 'reports.export', 'customers.view_pii']);
        ($this->ask)($seeing, 'fixture_people');
        $e = ($this->exportOf)($seeing);
        ($this->as)($seeing)->get(($this->link)($e))->assertOk();   // while they may see it

        $seeing->revokePermissionTo('customers.view_pii');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        ($this->as)($seeing->fresh())->get(($this->link)($e))->assertForbidden();
        expect((int) ($this->exportOf)($seeing)->downloads)->toBe(1);
    });

    it('refuses the file once the report itself is no longer allowed', function () {
        ($this->ask)($this->finance, 'low_stock');
        $e = ($this->exportOf)();
        $this->finance->revokePermissionTo('inventory.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        ($this->as)($this->finance->fresh())->get(($this->link)($e))->assertForbidden();
    });

    it('says so when the file is not ready yet, and when it has expired', function () {
        Queue::fake();
        ($this->ask)($this->finance);
        $waiting = ($this->exportOf)();
        ($this->as)($this->finance)->get(($this->link)($waiting))->assertStatus(409);

        Queue::fake();
        app(BuildReportExport::class)->handle((int) $waiting->id);
        $this->travel(3)->days();
        ($this->as)($this->finance)->get(($this->link)($waiting, 30))->assertStatus(410);   // past its date, even before the clean-up has run
    });
});

describe('expiring', function () {
    it('deletes finished files after their days, and downloads end', function () {
        ($this->ask)($this->finance);
        $e = ($this->exportOf)();
        $this->travel(2)->days();
        expect(app(ExpireReportExports::class)->handle())->toBe(['expired' => 0, 'stale' => 0])->and(Storage::disk('private')->exists((string) $e->file_key))->toBeTrue();

        $this->travel(2)->days();
        $result = app(ExpireReportExports::class)->handle();
        $after = ($this->exportOf)();

        expect($result)->toBe(['expired' => 1, 'stale' => 0])->and($after->status)->toBe('expired')->and($after->file_key)->toBeNull()->and(Storage::disk('private')->allFiles())->toBe([])
            ->and(app(ExpireReportExports::class)->handle())->toBe(['expired' => 0, 'stale' => 0]);   // a second run finds nothing
        expect(fn () => app(DownloadReportExport::class)->handle(($this->actor)($this->finance), true, false, $e->public_id))->toThrow(ReportException::class);
        ($this->as)($this->finance)->get('/admin/reports/exports')->assertOk()->assertSee('Deleted');
    });

    it('marks an export that never finished as failed, and frees the person to ask again', function () {
        Queue::fake();
        foreach (range(1, 3) as $i) {
            ($this->ask)($this->finance);
        }
        ($this->ask)($this->finance)->assertSessionHasErrors('export');
        $id = DB::table('report_exports')->orderBy('id')->value('id');
        DB::table('report_exports')->where('id', $id)->update(['status' => 'running', 'started_at' => now()]);
        $this->travel(31)->minutes();

        expect(app(ExpireReportExports::class)->handle())->toBe(['expired' => 0, 'stale' => 3])
            ->and(DB::table('report_exports')->where('error_code', 'stale')->count())->toBe(3);
        ($this->ask)($this->finance)->assertRedirect('/admin/reports/exports');
    });

    it('does not touch a running export that is still within its time', function () {
        Queue::fake();
        ($this->ask)($this->finance);
        $id = ($this->exportOf)()->id;
        DB::table('report_exports')->where('id', $id)->update(['status' => 'running', 'started_at' => now()]);
        $this->travel(29)->minutes();

        expect(app(ExpireReportExports::class)->handle()['stale'])->toBe(0)->and(DB::table('report_exports')->where('id', $id)->value('status'))->toBe('running');
    });

    it('is run every hour', function () {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'reports:expire-exports');

        expect($event)->not->toBeNull()->and($event->expression)->toBe('0 * * * *');
    });
});

describe('words', function () {
    it('shows the file list in Bangla with no missing words', function () {
        ($this->ask)($this->finance);
        $this->finance->forceFill(['locale' => 'bn'])->save();

        $page = ($this->as)($this->finance->fresh())->get('/admin/reports/exports')->assertOk();
        $page->assertSee('আমার রিপোর্ট ফাইল')->assertSee('নামান');
        expect($page->getContent())->not->toContain('reporting::');
        ($this->as)($this->finance->fresh())->get('/admin/reports')->assertOk()->assertSee('আমার রিপোর্ট ফাইল');
    });
});
