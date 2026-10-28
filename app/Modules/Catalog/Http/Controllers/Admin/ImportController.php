<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Admin;

use App\Modules\Catalog\Jobs\ProcessCatalogImport;
use App\Modules\Catalog\Models\CatalogImport;
use App\Modules\Catalog\Services\Import\ProductCsvExporter;
use App\Modules\Catalog\Services\Import\ProductCsvImporter;
use App\Modules\Platform\Support\CsvWriter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportController
{
    public function index(): View
    {
        return view('catalog::admin.import.index', ['imports' => CatalogImport::query()->latest('id')->limit(20)->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimes:csv,txt'],
            'dry_run' => ['sometimes', 'boolean'],
        ]);

        /** @var UploadedFile $file */
        $file = $data['file'];
        $path = 'catalog-imports/'.strtolower((string) Str::ulid()).'.csv';
        Storage::disk('private')->put($path, (string) file_get_contents($file->getRealPath()));

        return $this->queue($request, $file->getClientOriginalName(), $path, $request->boolean('dry_run', true));
    }

    public function show(CatalogImport $import): View
    {
        return view('catalog::admin.import.show', ['import' => $import]);
    }

    /** Apply a file that was just checked: same stored upload, new (non-dry) run. */
    public function apply(Request $request, CatalogImport $import): RedirectResponse
    {
        abort_unless($import->dry_run && $import->status === 'done' && Storage::disk('private')->exists($import->stored_path), 422);

        return $this->queue($request, $import->filename, $import->stored_path, false);
    }

    public function template(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $out = fopen('php://output', 'wb') ?: throw new \RuntimeException('Cannot open the output stream.');
            CsvWriter::bom($out);
            CsvWriter::row($out, ProductCsvImporter::COLUMNS);
            CsvWriter::row($out, ['TEE-001', 'physical', 'Cotton T-shirt', 'সুতির টি-শার্ট', '', '', 'Soft everyday tee', 'নরম প্রতিদিনের টি-শার্ট', '', '', 'clothing', '', '', 'standard', '1250.00', '1500.00', '', '250', 'yes', '40'], [14, 15, 17, 19]);
            fclose($out);
        }, 'products-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function export(ProductCsvExporter $exporter): StreamedResponse
    {
        return response()->streamDownload(function () use ($exporter): void {
            $out = fopen('php://output', 'wb') ?: throw new \RuntimeException('Cannot open the output stream.');
            $exporter->stream($out);
            fclose($out);
        }, 'products-'.now('Asia/Dhaka')->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function queue(Request $request, string $filename, string $path, bool $dryRun): RedirectResponse
    {
        $import = CatalogImport::query()->create([
            'created_by' => (int) $request->user('admin')?->getAuthIdentifier(),
            'filename' => mb_substr(basename($filename), 0, 200),
            'stored_path' => $path,
            'dry_run' => $dryRun,
        ]);

        ProcessCatalogImport::dispatch($import->id);

        return redirect()->route('admin.catalog.import.show', $import);
    }
}
