<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\Services\AuditLogQuery;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Phase 10, slice 4a: the audit log viewer and its export. The log itself is append-only and already written by every module.
 */

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, LanguageSeeder::class]);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    $this->auditor = catalogStaff(['audit.view']);
    $this->n = 0;
    /** Adds one audit row. Defaults: a staff member, a plain action, a fixed moment. */
    $this->row = function (array $o = []): int {
        return DB::table('audit_events')->insertGetId($o + [
            'actor_type' => 'staff', 'actor_id' => 7, 'actor_label' => 'Rahim Staff', 'action' => 'cms.page_updated', 'subject_type' => 'page', 'subject_id' => 'P'.(++$this->n),
            'before' => null, 'after' => null, 'ip' => '203.0.113.45', 'user_agent' => 'test', 'request_id' => 'req-'.$this->n, 'created_at' => '2026-12-01 06:00:00.000000',
        ]);
    };
    $this->list = fn (array $q = []) => $this->actingAs($this->auditor, 'admin')->get('/admin/audit?'.http_build_query($q));
    $this->ids = fn ($html) => array_map('intval', array_map(fn ($u) => basename($u), (function () use ($html) {
        preg_match_all('#/admin/audit/(\d+)"#', $html, $m);

        return array_map(fn ($id) => "/{$id}", $m[1]);
    })()));
});

describe('the list', function () {
    it('shows the newest first, fifty at a time, and every row exactly once across the pages', function () {
        $ids = [];
        foreach (range(1, 120) as $i) {
            $ids[] = ($this->row)(['created_at' => sprintf('2026-12-01 06:%02d:%02d.000000', intdiv($i, 60), $i % 60)]);
        }

        $p1 = ($this->ids)(($this->list)()->assertOk()->assertSee('Older entries')->getContent());
        $next = min($p1);
        $p2 = ($this->ids)(($this->list)(['before' => $next])->assertOk()->assertSee('Older entries')->getContent());
        $p3 = ($this->ids)(($this->list)(['before' => min($p2)])->assertOk()->assertDontSee('Older entries')->getContent());

        expect($p1)->toHaveCount(50)->and($p2)->toHaveCount(50)->and($p3)->toHaveCount(20)->and($p1[0])->toBe(max($ids))
            ->and(array_merge($p1, $p2, $p3))->toBe(array_reverse($ids));
    });

    it('says so when nothing matches, and shows the time in Dhaka', function () {
        ($this->list)()->assertOk()->assertSee('Nothing matches.');
        ($this->row)(['created_at' => '2026-12-01 06:00:00.000000']);
        ($this->list)()->assertOk()->assertSee('12:00')->assertSee('Rahim Staff')->assertSee('cms.page_updated');   // 06:00 UTC is 12:00 in Dhaka
    });

    it('never puts the before and after values in the list, so a secret cannot leak there', function () {
        ($this->row)(['after' => json_encode(['password' => 'hunter2-secret', 'name' => 'visible-in-detail-only'])]);
        $html = ($this->list)()->assertOk()->getContent();

        expect($html)->not->toContain('hunter2-secret')->not->toContain('visible-in-detail-only');
    });

    it('costs the same number of queries for a few rows or a full page', function () {
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            ($this->list)()->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        foreach (range(1, 3) as $i) {
            ($this->row)();
        }
        $count();
        $few = $count();
        foreach (range(1, 60) as $i) {
            ($this->row)();
        }

        expect($count())->toBe($few);
    });
});

describe('the filters', function () {
    it('keep a day to its Dhaka hours: midnight in Dhaka is 18:00 the evening before in UTC', function () {
        $late = ($this->row)(['created_at' => '2026-12-01 17:59:59.000000']);   // 23:59:59 on 1 December in Dhaka
        $early = ($this->row)(['created_at' => '2026-12-01 18:00:00.000000']);  // 00:00:00 on 2 December in Dhaka
        $before = ($this->row)(['created_at' => '2026-11-30 18:00:00.000000']); // 00:00:00 on 1 December in Dhaka

        expect(($this->ids)(($this->list)(['from' => '2026-12-01', 'to' => '2026-12-01'])->getContent()))->toBe([$before, $late])   // newest id first
            ->and(($this->ids)(($this->list)(['from' => '2026-12-02', 'to' => '2026-12-02'])->getContent()))->toBe([$early])
            ->and(($this->ids)(($this->list)(['from' => '2026-12-02'])->getContent()))->toBe([$early])
            ->and(($this->ids)(($this->list)(['to' => '2026-11-30'])->getContent()))->toBe([])
            ->and(($this->ids)(($this->list)(['from' => '2026-11-30', 'to' => '2026-12-02'])->getContent()))->toBe([$before, $early, $late]);
    });

    it('find a person by number or by the start of their name, and an action by its beginning', function () {
        $a = ($this->row)(['actor_id' => 7, 'actor_label' => 'Rahim Staff', 'action' => 'cms.page_updated']);
        $b = ($this->row)(['actor_id' => 8, 'actor_label' => 'Karim Staff', 'action' => 'cms.page_published']);
        $c = ($this->row)(['actor_id' => 9, 'actor_label' => 'Rahima Other', 'action' => 'payments.refund_approved']);

        expect(($this->ids)(($this->list)(['actor' => '8'])->getContent()))->toBe([$b])
            ->and(($this->ids)(($this->list)(['actor' => 'Rahim'])->getContent()))->toBe([$c, $a])      // starts with: Rahim Staff and Rahima Other
            ->and(($this->ids)(($this->list)(['actor' => 'Staff'])->getContent()))->toBe([])             // not "contains"
            ->and(($this->ids)(($this->list)(['action' => 'cms.'])->getContent()))->toBe([$b, $a])
            ->and(($this->ids)(($this->list)(['action' => 'cms.page_p'])->getContent()))->toBe([$b])
            ->and(($this->ids)(($this->list)(['actor' => 'Rahim', 'action' => 'cms.'])->getContent()))->toBe([$a]);
    });

    it('treat % and _ in what is typed as ordinary characters', function () {
        $a = ($this->row)(['action' => 'a_b', 'actor_label' => '100% Staff']);
        $b = ($this->row)(['action' => 'axb', 'actor_label' => '100 Staff']);

        expect(($this->ids)(($this->list)(['action' => 'a_b'])->getContent()))->toBe([$a])       // "_" is not "any one character"
            ->and(($this->ids)(($this->list)(['actor' => '100%'])->getContent()))->toBe([$a])     // "%" is not "anything"
            ->and(($this->ids)(($this->list)(['actor' => '%'])->getContent()))->toBe([]);          // nobody's name starts with a % sign
        expect($b)->toBeGreaterThan($a);
    });

    it('find what happened to one thing, and everything of one request', function () {
        $a = ($this->row)(['subject_type' => 'order', 'subject_id' => 'O1', 'request_id' => 'req-A']);
        $b = ($this->row)(['subject_type' => 'order', 'subject_id' => 'O2', 'request_id' => 'req-A']);
        $c = ($this->row)(['subject_type' => 'page', 'subject_id' => 'O1', 'request_id' => 'req-B']);

        expect(($this->ids)(($this->list)(['subject_type' => 'order'])->getContent()))->toBe([$b, $a])
            ->and(($this->ids)(($this->list)(['subject_type' => 'order', 'subject_id' => 'O1'])->getContent()))->toBe([$a])
            ->and(($this->ids)(($this->list)(['subject_id' => 'O1'])->getContent()))->toBe([$c, $a])
            ->and(($this->ids)(($this->list)(['request_id' => 'req-A'])->getContent()))->toBe([$b, $a]);
    });

    it('keep their place when paging', function () {
        foreach (range(1, 60) as $i) {
            ($this->row)(['action' => 'cms.page_updated']);
        }
        ($this->row)(['action' => 'orders.cancelled']);
        $html = ($this->list)(['action' => 'cms.'])->assertOk()->getContent();

        expect($html)->toContain('action=cms.')->toContain('before=')->and(($this->ids)($html))->toHaveCount(50);
    });

    it('refuse a bad date, a range the wrong way round and an action with odd characters', function () {
        ($this->row)();
        ($this->list)(['from' => 'yesterday'])->assertSessionHasErrors('from');
        ($this->list)(['from' => '2026-12-05', 'to' => '2026-12-01'])->assertSessionHasErrors('to');
        ($this->list)(['action' => "x' OR 1=1 --"])->assertSessionHasErrors('action');
        ($this->list)(['before' => 'abc'])->assertSessionHasErrors('before');
    });
});

describe('one entry', function () {
    it('shows before and after with secrets hidden, even a secret that got into the log some other way', function () {
        $id = ($this->row)([
            'before' => json_encode(['name' => 'Old name', 'api_token' => 'tok_live_12345']),
            'after' => json_encode(['name' => 'New name', 'store_password' => 'sslpass-999', 'nested' => ['password' => 'hunter2', 'note' => 'call 01712345678']]),
        ]);

        $html = $this->actingAs($this->auditor, 'admin')->get("/admin/audit/{$id}")->assertOk()->assertSee('Old name')->assertSee('New name')->assertSee('[redacted]')->getContent();
        expect($html)->not->toContain('tok_live_12345')->not->toContain('sslpass-999')->not->toContain('hunter2')->not->toContain('01712345678');
    });

    it('shows an address only roughly', function () {
        $v4 = ($this->row)(['ip' => '203.0.113.45']);
        $v6 = ($this->row)(['ip' => '2001:db8:85a3:8d3:1319:8a2e:370:7348']);

        $this->actingAs($this->auditor, 'admin')->get("/admin/audit/{$v4}")->assertOk()->assertSee('203.0.113.x')->assertDontSee('203.0.113.45');
        $this->actingAs($this->auditor, 'admin')->get("/admin/audit/{$v6}")->assertOk()->assertSee('2001:db8:85a3:8d3:…', false)->assertDontSee('370:7348');
        expect(AuditLogQuery::maskIp(null))->toBe('')->and(AuditLogQuery::maskIp(''))->toBe('');
        ($this->list)()->assertOk()->assertDontSee('203.0.113.45')->assertDontSee('370:7348');
    });

    it('is not found for an id that does not exist', function () {
        $this->actingAs($this->auditor, 'admin')->get('/admin/audit/999999')->assertNotFound();
        $this->actingAs($this->auditor, 'admin')->get('/admin/audit/abc')->assertNotFound();
    });
});

describe('the export', function () {
    it('is a spreadsheet-ready file of the filtered rows, without values, with addresses masked', function () {
        ($this->row)(['action' => 'cms.page_updated', 'after' => json_encode(['password' => 'hunter2']), 'actor_label' => '=HYPERLINK("http://evil.example")']);
        ($this->row)(['action' => 'orders.cancelled']);

        $response = $this->actingAs($this->auditor, 'admin')->get('/admin/audit/export?action=cms.')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->assertHeader('Cache-Control', 'no-store, private');
        $csv = $response->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim($csv))));

        expect($csv)->toStartWith("\xEF\xBB\xBF")->and($lines)->toHaveCount(2)->and($lines[0])->toContain('id,time_utc,actor_type,actor_id,actor_label,action,subject_type,subject_id,request_id,ip_masked')
            ->and($csv)->toContain("'=HYPERLINK")->not->toContain('hunter2')->not->toContain('203.0.113.45')->toContain('203.0.113.x')->not->toContain('orders.cancelled');
    });

    it('holds no more rows than the cap, and is itself recorded with what was asked for', function () {
        foreach (range(1, 8) as $i) {
            ($this->row)(['action' => 'cms.page_updated']);
        }
        config(['velmora.audit.export_cap' => 5]);

        $csv = $this->actingAs($this->auditor, 'admin')->get('/admin/audit/export?action=cms.&from=2026-12-01')->assertOk()->streamedContent();
        expect(count(array_filter(explode("\n", trim($csv)))))->toBe(6);   // the heading and five rows: the newest five

        $log = DB::table('audit_events')->where('action', 'audit.exported')->get();
        expect($log)->toHaveCount(1)->and(json_decode($log[0]->after, true))->toBe(['filters' => ['from' => '2026-12-01', 'action' => 'cms.'], 'row_cap' => 5])->and($log[0]->actor_id)->toBe($this->auditor->id);
    });

    it('is closed to those without the right, and cannot be pulled too often', function () {
        $this->actingAs(catalogStaff(['settings.view']), 'admin')->get('/admin/audit/export')->assertForbidden();
        foreach (range(1, 6) as $i) {
            $this->actingAs($this->auditor, 'admin')->get('/admin/audit/export')->assertOk()->streamedContent();
        }
        $this->actingAs($this->auditor, 'admin')->get('/admin/audit/export')->assertStatus(429);
    });
});

describe('who may look', function () {
    it('needs the audit right for the list, an entry and the export', function () {
        $id = ($this->row)();
        $other = catalogStaff(['settings.view', 'settings.manage']);
        foreach (['/admin/audit', "/admin/audit/{$id}", '/admin/audit/export'] as $url) {
            $this->actingAs($other, 'admin')->get($url)->assertForbidden();
        }
        auth('admin')->logout();
        $this->flushSession();
        $this->get('/admin/audit')->assertRedirect();
    });

    it('reads in Bangla', function () {
        ($this->row)();
        $this->auditor->forceFill(['locale' => 'bn'])->save();
        $this->actingAs($this->auditor->fresh(), 'admin')->get('/admin/audit')->assertOk()->assertSee('অডিট লগ')->assertSee('ছাঁকুন')->assertSee('CSV এক্সপোর্ট');
    });
});

describe('the log itself', function () {
    it('still refuses to be rewritten or emptied, and has the indexes the viewer uses', function () {
        $id = ($this->row)();

        expect(fn () => DB::table('audit_events')->where('id', $id)->update(['action' => 'x']))->toThrow(QueryException::class)
            ->and(fn () => DB::table('audit_events')->where('id', $id)->delete())->toThrow(QueryException::class);
        $indexes = DB::table('information_schema.statistics')->where('table_schema', DB::getDatabaseName())->where('table_name', 'audit_events')->pluck('index_name')->unique()->all();
        expect($indexes)->toContain('audit_events_created_at_index')->toContain('audit_events_action_created_at_index');
    });
});
