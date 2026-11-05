<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Actions;

use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Privacy\Exceptions\PrivacyException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** The owner reads their own ready, unexpired data copy. Anyone else, or anything else, is "not found". Every download is recorded. */
final class DownloadExport
{
    public function __construct(private readonly Clock $clock, private readonly AuditLogger $audit) {}

    /** @throws PrivacyException not_found | expired */
    public function handle(int $userId, string $publicId): string
    {
        $row = DB::table('privacy_requests')->where('public_id', $publicId)->where('user_id', $userId)->where('type', 'export')->first();   // unique public_id
        if ($row === null || $row->status === 'requested' || $row->status === 'failed') {
            throw new PrivacyException('not_found');
        }
        if ($row->status !== 'ready' || $row->file_key === null || ($row->expires_at !== null && $row->expires_at <= $this->clock->now()->format('Y-m-d H:i:s.u'))) {
            throw new PrivacyException('expired');
        }
        $disk = Storage::disk('private');
        if (! $disk->exists((string) $row->file_key)) {
            throw new PrivacyException('expired');
        }
        $this->audit->record('privacy.export_downloaded', 'privacy_request', [], [], null, (int) $row->id);

        return Crypt::decryptString((string) $disk->get((string) $row->file_key));
    }
}
