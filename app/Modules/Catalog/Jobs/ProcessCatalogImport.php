<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Jobs;

use App\Modules\Catalog\Models\CatalogImport;
use App\Modules\Catalog\Services\Import\ImportFailed;
use App\Modules\Catalog\Services\Import\ProductCsvImporter;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessCatalogImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1; // a half-run import is reported, never silently retried

    public int $timeout = 900;

    public function __construct(public readonly int $importId)
    {
        $this->onQueue('low');
    }

    public function handle(ProductCsvImporter $importer): void
    {
        $import = CatalogImport::query()->findOrFail($this->importId);
        $import->forceFill(['status' => 'running', 'started_at' => now('UTC')])->save();

        $disk = Storage::disk('private');
        $tmp = tempnam(sys_get_temp_dir(), 'imp');

        try {
            $in = $disk->readStream($import->stored_path) ?: throw new ImportFailed('unreadable');
            file_put_contents($tmp, $in);
            fclose($in);

            $report = $importer->run($tmp, new Actor(ActorType::Staff, $import->created_by), $import->dry_run);

            $import->forceFill([
                'status' => 'done', 'total_rows' => $report->total(), 'created_count' => $report->created, 'updated_count' => $report->updated, 'error_count' => $report->failed,
                'results' => $report->rows, 'failure' => $report->ignoredColumns === [] ? null : 'ignored:'.implode(',', $report->ignoredColumns), 'finished_at' => now('UTC'),
            ])->save();

            if (! $import->dry_run) {
                $disk->delete($import->stored_path); // the uploaded file is not kept once it has been applied
            }
        } catch (ImportFailed $e) {
            $import->forceFill(['status' => 'failed', 'failure' => $e->key.($e->params === [] ? '' : ':'.json_encode($e->params, JSON_UNESCAPED_UNICODE)), 'finished_at' => now('UTC')])->save();
        } catch (Throwable $e) {
            $import->forceFill(['status' => 'failed', 'failure' => 'unexpected', 'finished_at' => now('UTC')])->save();
            report($e);
        } finally {
            @unlink($tmp);
        }
    }
}
