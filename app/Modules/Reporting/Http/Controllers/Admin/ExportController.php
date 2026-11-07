<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers\Admin;

use App\Modules\Reporting\Actions\DownloadReportExport;
use App\Modules\Reporting\Actions\RequestReportExport;
use App\Modules\Reporting\Exceptions\ReportException;
use App\Modules\Reporting\Http\Concerns\ActsAsStaff;
use App\Modules\Reporting\Http\Requests\ReportFilterRequest;
use App\Modules\Reporting\Reports\ReportCatalogue;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Asking for a report as a file, seeing one's own requests, and downloading a finished file. Asking needs reports.export plus the report's own permissions (set on the route and
 * checked here); downloading needs a signed, short link AND the person signed in, and only the person who asked can open the file.
 */
class ExportController
{
    use ActsAsStaff;

    public function store(ReportFilterRequest $request, RequestReportExport $export, ReportCatalogue $catalogue, string $code): RedirectResponse
    {
        $user = $request->user('admin');
        $report = $catalogue->find($code) ?? abort(404);
        abort_unless($catalogue->allowed($user, $report), 403);

        try {
            $export->handle($this->actor($request), $code, $request->filter(), $user?->can('reports.finance.view') === true, $user?->can('customers.view_pii') === true, app()->getLocale());
        } catch (ReportException $e) {
            return back()->withInput()->withErrors(['export' => __('reporting::exports.errors.'.$e->reason, $e->params)]);
        }

        return redirect()->route('admin.reports.exports')->with('status', __('reporting::exports.requested'));
    }

    public function index(Request $request, ReportCatalogue $catalogue): View
    {
        $rows = DB::table('report_exports')->where('requested_by', $this->actor($request)->id)->orderByDesc('id')->limit(50)->get();   // index (requested_by, id)
        $minutes = max(1, (int) config('reporting.exports.link_minutes'));

        return view('reporting::admin.exports', ['exports' => $rows->map(function ($e) use ($catalogue, $minutes): array {
            $ready = $e->status === 'ready';

            return [
                'report' => $e->report_code, 'known' => $catalogue->find((string) $e->report_code) !== null, 'status' => (string) $e->status, 'rows' => $e->row_count, 'size' => $e->size_bytes,
                'asked' => CarbonImmutable::parse((string) $e->requested_at, 'UTC'), 'expires' => $e->expires_at === null ? null : CarbonImmutable::parse((string) $e->expires_at, 'UTC'),
                'error' => $e->error_code, 'link' => $ready ? URL::temporarySignedRoute('admin.reports.exports.download', now()->addMinutes($minutes), ['publicId' => $e->public_id]) : null,
            ];
        })->all(), 'minutes' => $minutes]);
    }

    public function download(Request $request, DownloadReportExport $download, ReportCatalogue $catalogue, string $publicId): StreamedResponse
    {
        $user = $request->user('admin');
        try {
            $file = $download->handle($this->actor($request), $user?->can('reports.finance.view') === true, $user?->can('customers.view_pii') === true, $publicId);
        } catch (ReportException $e) {
            abort(match ($e->reason) {
                'expired' => 410,
                'not_ready' => 409,
                'forbidden' => 403,
                default => 404,
            });
        }

        $code = (string) DB::table('report_exports')->where('public_id', $publicId)->value('report_code');
        $report = $catalogue->find($code);
        abort_unless($report !== null && $catalogue->allowed($user, $report), 403);   // the report's own permissions still hold

        $disk = Storage::disk((string) config('reporting.exports.disk'));
        $stream = $disk->readStream($file['key']);
        abort_unless(is_resource($stream), 404);

        return response()->streamDownload(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, $file['name'], ['Content-Type' => 'text/csv; charset=utf-8', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store, private']);
    }
}
