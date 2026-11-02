<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Actions;

use App\Modules\Catalog\Contracts\VariantDirectory;
use App\Modules\DigitalProducts\Exceptions\DigitalException;
use App\Modules\DigitalProducts\Models\DigitalAsset;
use App\Modules\DigitalProducts\Models\DigitalAssetVersion;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Staff put the file of a digital product into the private bucket. Every upload is a new version; older versions stay. The file is checked by its own
 * bytes (the name and the type the browser claims are not trusted), stored under a name we make, and fingerprinted. Who may upload (`digital.manage`) is
 * decided by the caller.
 */
final class UploadDigitalAsset
{
    public function __construct(private readonly VariantDirectory $variants, private readonly AuditLogger $audit, private readonly Clock $clock) {}

    /** @throws DigitalException */
    public function handle(Actor $actor, string $sku, string $path, string $originalName): DigitalAssetVersion
    {
        $variant = $this->variants->findBySku($sku) ?? throw new DigitalException('variant_unknown');
        if ($variant->productType !== 'digital') {
            throw new DigitalException('variant_not_digital');
        }

        $name = $this->cleanName($originalName);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowed = (array) config('digital.allowed');
        if (! isset($allowed[$extension])) {
            throw new DigitalException('type_not_allowed', ['types' => implode(', ', array_keys($allowed))]);
        }
        $size = is_file($path) ? (int) filesize($path) : 0;
        if ($size < 1) {
            throw new DigitalException('file_empty');
        }
        if ($size > (int) config('digital.max_upload_mb') * 1024 * 1024) {
            throw new DigitalException('file_too_large', ['mb' => (int) config('digital.max_upload_mb')]);
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (! in_array($mime, $allowed[$extension], true)) {
            throw new DigitalException('content_mismatch');
        }

        $key = 'digital/'.strtolower((string) Str::ulid()).'/'.bin2hex(random_bytes(12));   // nothing from the upload is in the stored name
        $disk = Storage::disk((string) config('digital.disk'));
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new DigitalException('file_empty');
        }
        try {
            $disk->writeStream($key, $stream);
        } finally {
            fclose($stream);
        }

        try {
            return Transactions::run(function () use ($actor, $variant, $key, $name, $mime, $size, $path): DigitalAssetVersion {
                DB::table('digital_assets')->insertOrIgnore(['public_id' => strtolower((string) Str::ulid()), 'variant_id' => $variant->id, 'created_by' => $actor->id, 'created_at' => $this->clock->now(), 'updated_at' => $this->clock->now()]);
                $asset = DigitalAsset::query()->where('variant_id', $variant->id)->lockForUpdate()->firstOrFail();   // uploads of one product wait for each other, so numbers are in order
                $number = (int) DigitalAssetVersion::query()->where('asset_id', $asset->id)->max('version') + 1;
                $version = DigitalAssetVersion::query()->create([
                    'asset_id' => $asset->id, 'version' => $number, 'storage_key' => $key, 'original_filename' => $name, 'mime' => $mime, 'size_bytes' => $size,
                    'sha256' => hash_file('sha256', $path), 'uploaded_by' => $actor->id, 'created_at' => $this->clock->now(),
                ]);
                $this->audit->record('digital.asset_uploaded', $asset, [], ['version' => $number, 'sku' => $variant->sku, 'sha256' => $version->sha256, 'size_bytes' => $size], $actor);

                return $version;
            });
        } catch (Throwable $e) {
            $disk->delete($key);   // no file is left behind without a record

            throw $e;
        }
    }

    /** The name the customer will see when they save the file: no folders, no control characters, a sensible length. */
    private function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F"<>|:*?]+/u', '', $name);
        $name = trim($name, ". \t");

        return mb_substr($name, -150);
    }
}
