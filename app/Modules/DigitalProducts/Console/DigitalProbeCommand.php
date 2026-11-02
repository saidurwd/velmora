<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Console;

use App\Modules\DigitalProducts\Actions\AllocateLicenseKeys;
use App\Modules\DigitalProducts\Actions\DownloadDigitalFile;
use App\Modules\DigitalProducts\Actions\GrantEntitlements;
use App\Modules\DigitalProducts\Actions\ImportLicenseKeys;
use App\Modules\DigitalProducts\Exceptions\DigitalException;
use App\Modules\DigitalProducts\Models\Entitlement;
use App\Modules\Platform\DTOs\Actor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Concurrency-test entry point: each OS process (its own database connection) does ONE thing to ONE order and prints a JSON line.
 * Never available in production.
 */
class DigitalProbeCommand extends Command
{
    protected $signature = 'digital:probe {action : grant|download|allocate|import} {--order=} {--entitlement=} {--sku=} {--file=} {--start-at=}';

    protected $description = 'Grant the rights to download for one paid order, or download once, and print the outcome as JSON (concurrency testing only)';

    protected $hidden = true;

    public function handle(GrantEntitlements $grant, DownloadDigitalFile $download, AllocateLicenseKeys $allocate, ImportLicenseKeys $import): int
    {
        if (app()->environment('production')) {
            $this->error('Not available in production.');

            return self::FAILURE;
        }

        if ($at = (float) $this->option('start-at')) {
            while (microtime(true) < $at) {
                usleep(200);
            }
        }

        try {
            if ($this->argument('action') === 'allocate') {
                $this->line(json_encode(['ok' => true, 'given' => $allocate->forEntitlement((int) Entitlement::query()->where('public_id', (string) $this->option('entitlement'))->value('id'))], JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }

            if ($this->argument('action') === 'import') {
                $result = $import->handle(Actor::system('probe'), (string) $this->option('sku'), (string) file_get_contents((string) $this->option('file')));
                $this->line(json_encode(['ok' => true, 'added' => $result->added, 'duplicates' => $result->duplicates, 'filled' => $result->filled], JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }

            if ($this->argument('action') === 'download') {
                // no storage service is needed to prove the counter: the address is signed locally, with a stand-in signer
                Storage::disk((string) config('digital.disk'))->buildTemporaryUrlsUsing(fn (string $path, $expiration) => 'https://files.test/'.$path.'?exp='.$expiration->getTimestamp());
                $link = $download->handle(Entitlement::query()->where('public_id', (string) $this->option('entitlement'))->firstOrFail(), null, '127.0.0.1', 'probe');
                $this->line(json_encode(['ok' => true, 'granted' => true, 'remaining' => $link->remaining], JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }

            $this->line(json_encode(['ok' => true, 'made' => $grant->handle((int) $this->option('order'))], JSON_THROW_ON_ERROR));
        } catch (DigitalException $e) {
            $this->line(json_encode(['ok' => true, 'granted' => false, 'reason' => $e->reason], JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            $this->line(json_encode(['ok' => false, 'reason' => 'error', 'class' => $e::class, 'message' => $e->getMessage()], JSON_THROW_ON_ERROR));
        }

        return self::SUCCESS;
    }
}
