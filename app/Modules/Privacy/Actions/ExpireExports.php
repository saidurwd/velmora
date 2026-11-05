<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Actions;

use App\Modules\Platform\Contracts\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Deletes data copies that are past their date (every day). Returns how many. */
final class ExpireExports
{
    public function __construct(private readonly Clock $clock) {}

    public function handle(): int
    {
        $now = $this->clock->now();
        $count = 0;
        foreach (DB::table('privacy_requests')->where('type', 'export')->where('status', 'ready')->where('expires_at', '<=', $now)->limit(500)->get(['id', 'file_key']) as $row) {   // index (status, expires_at)
            if ($row->file_key !== null) {
                Storage::disk('private')->delete((string) $row->file_key);
            }
            $count += DB::table('privacy_requests')->where('id', $row->id)->where('status', 'ready')->update(['status' => 'expired', 'file_key' => null, 'updated_at' => $now]);
        }

        return $count;
    }
}
