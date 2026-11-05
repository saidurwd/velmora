<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Actions;

use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Settings;
use App\Modules\Privacy\Services\PersonalDataCatalog;
use App\Modules\Privacy\Services\PrivacyMessages;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Builds one customer's data copy: every module adds its own part for THIS user only. The file is encrypted on the private disk and deleted after a few days.
 * The request is claimed with a conditional update, so a job delivered twice builds once.
 */
final class BuildExport
{
    public function __construct(private readonly PersonalDataCatalog $catalog, private readonly Clock $clock, private readonly Settings $settings, private readonly AuditLogger $audit, private readonly PrivacyMessages $messages) {}

    public function handle(int $requestId): void
    {
        $request = DB::table('privacy_requests')->where('id', $requestId)->where('type', 'export')->first();
        if ($request === null || $request->status !== 'requested') {
            return;   // already built, failed or gone
        }
        $userId = (int) $request->user_id;

        try {
            $sections = [];
            foreach ($this->catalog->exporters() as $exporter) {
                $sections[$exporter->section()] = $exporter->export($userId);
            }
            $json = json_encode(['generated_at' => $this->clock->now()->format('Y-m-d\TH:i:s\Z'), 'request' => $request->public_id] + $sections, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $key = 'privacy/exports/'.$request->public_id.'.json.enc';
            Storage::disk('private')->put($key, Crypt::encryptString($json));
        } catch (Throwable $e) {
            DB::table('privacy_requests')->where('id', $requestId)->where('status', 'requested')->update(['status' => 'failed', 'open_key' => null, 'failure' => mb_substr($e::class, 0, 200), 'completed_at' => $this->clock->now(), 'updated_at' => $this->clock->now()]);
            $this->audit->record('privacy.export_failed', 'privacy_request', [], ['error' => $e::class], null, $requestId);

            return;
        }

        $now = $this->clock->now();
        $days = max(1, $this->settings->int('privacy.export_days', (int) config('privacy.export_days', 7)));
        $claimed = DB::table('privacy_requests')->where('id', $requestId)->where('status', 'requested')->update([
            'status' => 'ready', 'open_key' => null, 'file_key' => $key, 'file_bytes' => strlen($json), 'completed_at' => $now, 'expires_at' => $now->addDays($days), 'updated_at' => $now,
        ]);
        if ($claimed !== 1) {
            Storage::disk('private')->delete($key);   // another worker finished first

            return;
        }
        $this->audit->record('privacy.export_ready', 'privacy_request', [], ['sections' => array_keys($sections)], null, $requestId);
        $this->messages->tell('export_ready', $this->messages->contactOf($userId), (string) $request->locale, ['link' => route('account.privacy', ['locale' => $request->locale]), 'days' => (string) $days], 'privacy.export_ready|'.$request->public_id, $userId);
    }
}
