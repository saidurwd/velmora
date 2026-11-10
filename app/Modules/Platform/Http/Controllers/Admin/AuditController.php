<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Services\AuditLogQuery;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\CsvWriter;
use App\Modules\Platform\Support\Redactor;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The audit log: look (filter, page, open one) and export, all behind audit.view. Secrets never reach the page: they are redacted when written and again here. */
class AuditController
{
    public function index(Request $request, AuditLogQuery $log): View
    {
        $filters = $this->filters($request);
        $before = $request->integer('before') ?: null;

        return view('platform::admin.audit.index', ['filters' => $filters, 'page' => $log->page($filters, $before), 'before' => $before]);
    }

    public function show(int $event): View
    {
        $row = DB::table('audit_events')->where('id', $event)->first() ?? abort(404);
        $decode = fn (?string $json): array => $json === null ? [] : Redactor::array((array) json_decode($json, true));

        return view('platform::admin.audit.show', ['row' => $row, 'before' => $decode($row->before), 'after' => $decode($row->after), 'ip' => AuditLogQuery::maskIp($row->ip)]);
    }

    public function export(Request $request, AuditLogQuery $log, AuditLogger $audit): StreamedResponse
    {
        $filters = $this->filters($request);
        $user = $request->user('admin');
        abort_if($user === null, 403);
        $audit->record('audit.exported', 'audit', [], ['filters' => array_filter($filters, fn ($v) => $v !== null && $v !== ''), 'row_cap' => AuditLogQuery::exportCap()], new Actor(ActorType::Staff, (int) $user->getAuthIdentifier(), $user->public_id, (string) $user->name));

        return response()->streamDownload(function () use ($log, $filters): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            CsvWriter::bom($out);
            CsvWriter::row($out, ['id', 'time_utc', 'actor_type', 'actor_id', 'actor_label', 'action', 'subject_type', 'subject_id', 'request_id', 'ip_masked'], [0, 3]);
            foreach ($log->builder($filters)->orderByDesc('id')->limit(AuditLogQuery::exportCap())->select(AuditLogQuery::LIST_COLUMNS)->cursor() as $r) {
                CsvWriter::row($out, [$r->id, $r->created_at, $r->actor_type, $r->actor_id, $r->actor_label, $r->action, $r->subject_type, $r->subject_id, $r->request_id, AuditLogQuery::maskIp($r->ip)], [0, 3]);
            }
            fclose($out);
        }, 'audit-log.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /** @return array{from: string|null, to: string|null, actor: string|null, action: string|null, subject_type: string|null, subject_id: string|null, request_id: string|null} */
    private function filters(Request $request): array
    {
        $v = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'], 'actor' => ['nullable', 'string', 'max:100'],
            'action' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.\-]*$/'], 'subject_type' => ['nullable', 'string', 'max:100'], 'subject_id' => ['nullable', 'string', 'max:64'],
            'request_id' => ['nullable', 'string', 'max:36'], 'before' => ['nullable', 'integer', 'min:1'],
        ]);

        return [
            'from' => $v['from'] ?? null, 'to' => $v['to'] ?? null, 'actor' => $v['actor'] ?? null, 'action' => $v['action'] ?? null,
            'subject_type' => $v['subject_type'] ?? null, 'subject_id' => $v['subject_id'] ?? null, 'request_id' => $v['request_id'] ?? null,
        ];
    }
}
