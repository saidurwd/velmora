<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Actions;

use App\Modules\DigitalProducts\DTOs\DownloadLink;
use App\Modules\DigitalProducts\Enums\DownloadOutcome;
use App\Modules\DigitalProducts\Exceptions\DigitalException;
use App\Modules\DigitalProducts\Models\Entitlement;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Support\Settings;
use App\Modules\Platform\Support\Transactions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The one place a download happens. Who may download (the owner, or a guest who proved the order's phone) is decided by the caller BEFORE this runs.
 * Here: the newest file must exist (a customer is never charged a download for a file that is not there); then ONE conditional UPDATE adds one to the
 * counter only while the right is active, not expired and under its limit, so parallel downloads can never pass the limit; then a signed address valid for
 * a minute or so is made. The counter, the address and the log row are one transaction: if any part fails, no download is used. A refusal is logged too and
 * never uses a download.
 */
final class DownloadDigitalFile
{
    public function __construct(private readonly Clock $clock, private readonly Settings $settings) {}

    /** @throws DigitalException download_no_file | download_revoked | download_expired | download_limit_reached */
    public function handle(Entitlement $entitlement, ?int $userId, ?string $ip, ?string $userAgent): DownloadLink
    {
        $version = DB::table('digital_asset_versions as v')->join('digital_assets as a', 'a.id', '=', 'v.asset_id')->where('a.variant_id', $entitlement->variant_id)
            ->orderByDesc('v.version')->first(['v.id', 'v.storage_key', 'v.original_filename', 'v.mime', 'v.version']);   // newest first: unique (asset_id, version)
        if ($version === null) {
            $this->log($entitlement, null, $userId, DownloadOutcome::NoFile, $ip, $userAgent);

            throw new DigitalException('download_no_file');
        }

        $now = $this->clock->now();
        $ttl = max(10, $this->settings->int('digital.link_ttl_seconds', (int) config('digital.link_ttl_seconds')));

        $link = Transactions::run(function () use ($entitlement, $version, $userId, $ip, $userAgent, $now, $ttl): ?DownloadLink {
            $used = DB::table('entitlements')->where('id', $entitlement->id)->where('status', 'active')
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now))
                ->where(fn ($q) => $q->whereNull('download_limit')->orWhereColumn('downloads_used', '<', 'download_limit'))
                ->update(['downloads_used' => DB::raw('downloads_used + 1'), 'updated_at' => $now]);
            if ($used === 0) {
                return null;   // refused: nothing was used
            }

            DB::table('entitlements')->where('id', $entitlement->id)->whereNull('first_used_at')->update(['first_used_at' => $now]);   // the first download is the first use (set once)
            $validUntil = $now->addSeconds($ttl);
            $url = Storage::disk((string) config('digital.disk'))->temporaryUrl((string) $version->storage_key, $validUntil, [
                'ResponseContentDisposition' => $this->disposition((string) $version->original_filename),
                'ResponseContentType' => (string) $version->mime,
            ]);
            $this->log($entitlement, (int) $version->id, $userId, DownloadOutcome::Granted, $ip, $userAgent);
            $row = DB::table('entitlements')->where('id', $entitlement->id)->sole(['download_limit', 'downloads_used']);

            return new DownloadLink($url, (string) $version->original_filename, $row->download_limit === null ? null : (int) $row->download_limit - (int) $row->downloads_used, $validUntil);
        });

        if ($link === null) {
            $outcome = $this->whyRefused($entitlement, $now);
            $this->log($entitlement, null, $userId, $outcome, $ip, $userAgent);

            throw new DigitalException('download_'.$outcome->value);
        }

        return $link;
    }

    /** A person tried to download something that is not theirs. They are told it was not found; we remember it. */
    public function logNotOwner(Entitlement $entitlement, ?int $userId, ?string $ip, ?string $userAgent): void
    {
        $this->log($entitlement, null, $userId, DownloadOutcome::NotOwner, $ip, $userAgent);
    }

    private function whyRefused(Entitlement $entitlement, CarbonImmutable $now): DownloadOutcome
    {
        $row = DB::table('entitlements')->where('id', $entitlement->id)->first(['status', 'expires_at']);
        if ($row === null || $row->status !== 'active') {
            return DownloadOutcome::Revoked;
        }
        if ($row->expires_at !== null && CarbonImmutable::parse((string) $row->expires_at, 'UTC')->lessThanOrEqualTo($now)) {
            return DownloadOutcome::Expired;
        }

        return DownloadOutcome::LimitReached;
    }

    private function log(Entitlement $entitlement, ?int $versionId, ?int $userId, DownloadOutcome $outcome, ?string $ip, ?string $userAgent): void
    {
        DB::table('download_logs')->insert([
            'entitlement_id' => $entitlement->id, 'version_id' => $versionId, 'user_id' => $userId, 'outcome' => $outcome->value,
            'ip' => $ip === null ? null : mb_substr($ip, 0, 45), 'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255), 'created_at' => $this->clock->now(),
        ]);
    }

    /** The name the browser saves the file as: a plain fallback for old browsers and the real (UTF-8, Bangla too) name for the rest. */
    private function disposition(string $filename): string
    {
        $ascii = trim((string) preg_replace('/[^A-Za-z0-9._ -]+/', '_', $filename), ' ._') ?: 'download';

        return 'attachment; filename="'.$ascii.'"; filename*=UTF-8\'\''.rawurlencode($filename);
    }
}
