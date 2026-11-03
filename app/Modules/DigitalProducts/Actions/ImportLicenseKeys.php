<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Actions;

use App\Modules\Catalog\Contracts\VariantDirectory;
use App\Modules\DigitalProducts\DTOs\KeyImportResult;
use App\Modules\DigitalProducts\Exceptions\DigitalException;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Staff add keys to a product's pool from a plain list, one key per line. Each key is encrypted and fingerprinted; a key already in the pool (the database
 * UNIQUE decides, even for two files at once) is counted as a duplicate and skipped. The file is read and thrown away: it is never stored, and the keys are
 * never logged or audited. Keys now available go first to customers who are already waiting. Who may import (`digital.manage`) is decided by the caller.
 */
final class ImportLicenseKeys
{
    public const MAX_LINES = 5000;

    public const MAX_KEY_LENGTH = 200;

    public const MAX_BYTES = 1_000_000;

    public function __construct(private readonly VariantDirectory $variants, private readonly AllocateLicenseKeys $allocate, private readonly AuditLogger $audit, private readonly Clock $clock) {}

    /** @throws DigitalException */
    public function handle(Actor $actor, string $sku, string $content): KeyImportResult
    {
        $variant = $this->variants->findBySku($sku) ?? throw new DigitalException('variant_unknown');
        if ($variant->productType !== 'digital') {
            throw new DigitalException('variant_not_digital');
        }
        if (strlen($content) > self::MAX_BYTES) {
            throw new DigitalException('keys_file_too_large');
        }
        if (! mb_check_encoding($content, 'UTF-8')) {
            throw new DigitalException('keys_not_utf8');
        }

        $lines = preg_split('/\r\n|\r|\n/', (string) preg_replace('/^\xEF\xBB\xBF/', '', $content)) ?: [];
        $keys = [];
        $invalid = 0;
        foreach ($lines as $i => $line) {
            $key = trim($line);
            if ($key === '' || ($i === 0 && strtolower($key) === 'key')) {
                continue;   // blank lines and an optional header
            }
            if (count($keys) + $invalid >= self::MAX_LINES) {
                throw new DigitalException('keys_too_many', ['max' => self::MAX_LINES]);
            }
            if (mb_strlen($key) > self::MAX_KEY_LENGTH || preg_match('/[\x00-\x1F\x7F]/u', $key) === 1) {
                $invalid++;

                continue;
            }
            $keys[$key] = true;   // the same key twice in one file counts once here
        }
        $repeatedInFile = count(array_filter($lines, fn ($l) => trim($l) !== '')) - $invalid - count($keys) - (isset($lines[0]) && strtolower(trim($lines[0])) === 'key' ? 1 : 0);

        DB::table('license_key_pools')->insertOrIgnore(['public_id' => strtolower((string) Str::ulid()), 'variant_id' => $variant->id, 'created_by' => $actor->id, 'created_at' => $this->clock->now(), 'updated_at' => $this->clock->now()]);
        $pool = (int) DB::table('license_key_pools')->where('variant_id', $variant->id)->value('id');

        $added = 0;
        foreach (array_chunk(array_keys($keys), 500) as $chunk) {
            $rows = [];
            foreach ($chunk as $key) {
                $rows[] = ['pool_id' => $pool, 'key_encrypted' => Crypt::encryptString((string) $key), 'fingerprint' => self::fingerprint((string) $key), 'status' => 'available', 'created_at' => $this->clock->now()];
            }
            $added += (int) Transactions::run(fn () => DB::table('license_keys')->insertOrIgnore($rows));   // retried a few times if the database picks this insert as a deadlock victim
        }
        $duplicates = count($keys) - $added + max(0, $repeatedInFile);
        $filled = $this->allocate->fillWaiting($variant->id);

        $this->audit->record('digital.keys_imported', 'license_key_pool', [], ['sku' => $variant->sku, 'added' => $added, 'duplicates' => $duplicates, 'invalid' => $invalid, 'given_to_waiting' => $filled], $actor, $pool);

        return new KeyImportResult($added, $duplicates, $invalid, $filled);
    }

    /** A fingerprint that finds a repeat but cannot give the key back: an HMAC under the app key. */
    public static function fingerprint(string $key): string
    {
        return hash_hmac('sha256', $key, (string) config('app.key'));
    }
}
