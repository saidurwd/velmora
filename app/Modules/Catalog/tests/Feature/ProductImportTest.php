<?php

declare(strict_types=1);

use App\Modules\Catalog\Actions\SaveAttribute;
use App\Modules\Catalog\Actions\SaveCategory;
use App\Modules\Catalog\Actions\SaveVariant;
use App\Modules\Catalog\DTOs\TranslationInput;
use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Catalog\Jobs\ProcessCatalogImport;
use App\Modules\Catalog\Models\CatalogImport;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\Import\ImportFailed;
use App\Modules\Catalog\Services\Import\ImportReport;
use App\Modules\Catalog\Services\Import\ProductCsvImporter;
use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\Inventory\Contracts\StockInitialiser;
use App\Modules\Inventory\Database\Seeders\StockLocationSeeder;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\CsvWriter;
use App\Modules\Pricing\Database\Seeders\PriceListSeeder;
use App\Modules\Pricing\Database\Seeders\TaxClassSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

const IMPORT_HEADER = ['sku', 'product_type', 'name_en', 'name_bn', 'category', 'price', 'compare_at', 'track_inventory', 'opening_stock'];

beforeEach(function () {
    $this->seed([RolesAndPermissionsSeeder::class, LanguageSeeder::class, TaxClassSeeder::class, PriceListSeeder::class, StockLocationSeeder::class]);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    app(SaveCategory::class)->handle(Actor::system('t'), null, null, [new TranslationInput('en', 'Clothing', 'clothing')]);
    $this->actor = Actor::system('import');
});

/** @param  list<list<string>>  $rows */
function csvFile(array $rows, array $header = IMPORT_HEADER): string
{
    $path = tempnam(sys_get_temp_dir(), 'csv');
    $out = fopen($path, 'wb');
    CsvWriter::bom($out);
    fputcsv($out, $header, ',', '"', '');
    foreach ($rows as $r) {
        fputcsv($out, $r, ',', '"', '');
    }
    fclose($out);

    return $path;
}

function runImport(string $path, bool $dry = false, ?Actor $actor = null): ImportReport
{
    return app(ProductCsvImporter::class)->run($path, $actor ?? Actor::system('import'), $dry);
}

const GOOD_ROWS = [
    ['tee-1', 'physical', 'Cotton Tee', 'সুতির টি-শার্ট', 'clothing', '1,250.50', '1500', 'yes', '40'],
    ['EBOOK-1', 'digital', 'Style Guide', '', 'clothing', '300', '', '', ''],
];

it('creates bilingual products with price, category and opening stock', function () {
    $report = runImport(csvFile(GOOD_ROWS));

    expect([$report->created, $report->updated, $report->failed])->toBe([2, 0, 0]);

    $variant = ProductVariant::where('sku', 'TEE-1')->firstOrFail();
    expect($variant->product->translate('name', 'bn'))->toBe('সুতির টি-শার্ট')
        ->and($variant->product->categories)->toHaveCount(1)
        ->and($variant->track_inventory)->toBeTrue()
        ->and(DB::table('prices')->where('variant_id', $variant->id)->first()->amount_minor)->toBe(125050)
        ->and((int) DB::table('stock_levels')->where('variant_id', $variant->id)->value('on_hand'))->toBe(40)
        ->and(ProductVariant::where('sku', 'EBOOK-1')->firstOrFail()->track_inventory)->toBeFalse();
});

it('is idempotent: re-importing the same file changes nothing', function () {
    $file = csvFile(GOOD_ROWS);
    runImport($file);
    $before = [Product::count(), DB::table('prices')->count(), DB::table('stock_movements')->count(), DB::table('product_translations')->orderBy('id')->pluck('slug')->all()];

    $report = runImport($file);

    expect([$report->created, $report->updated, $report->failed])->toBe([0, 2, 0])
        ->and([Product::count(), DB::table('prices')->count(), DB::table('stock_movements')->count(), DB::table('product_translations')->orderBy('id')->pluck('slug')->all()])->toBe($before)
        ->and((int) DB::table('stock_levels')->value('on_hand'))->toBe(40); // opening stock is not added twice
});

it('keeps existing values where the file leaves cells blank', function () {
    runImport(csvFile([GOOD_ROWS[0]]));
    runImport(csvFile([['TEE-1', 'physical', 'Cotton Tee v2', '', '', '', '', '', '']]));

    $product = ProductVariant::where('sku', 'TEE-1')->firstOrFail()->product;
    expect($product->translate('name', 'en'))->toBe('Cotton Tee v2')
        ->and($product->translate('name', 'bn'))->toBe('সুতির টি-শার্ট')
        ->and($product->categories)->toHaveCount(1)
        ->and(DB::table('prices')->value('amount_minor'))->toBe(125050);
});

it('reports bad rows one by one and still imports the good ones', function () {
    $report = runImport(csvFile([
        GOOD_ROWS[0],
        ['x', 'physical', 'Bad SKU', '', '', '', '', '', ''],
        ['OK-2', 'physical', 'Fine', '', 'nope', '', '', '', ''],
        ['OK-3', 'digital', 'Digital stock', '', '', '10', '', 'yes', ''],
        ['OK-4', 'physical', 'Cheap compare', '', '', '100', '50', '', ''],
        ['TEE-1', 'physical', 'Dupe', '', '', '', '', '', ''],
        ['OK-6', 'robot', 'Wrong type', '', '', '', '', '', ''],
    ]));

    $errors = collect($report->rows)->where('outcome', 'error')->mapWithKeys(fn ($r) => [$r['line'] => $r['errors'][0]['key']])->all();

    expect($report->created)->toBe(1)
        ->and($errors)->toBe([3 => 'sku_invalid', 4 => 'unknown_category', 5 => 'track_not_allowed', 6 => 'compare_too_low', 7 => 'duplicate_in_file', 8 => 'type_invalid'])
        ->and(Product::count())->toBe(1);
});

it('a dry run reports exactly what a real run does and writes nothing', function () {
    $file = csvFile([...GOOD_ROWS, ['x', 'physical', 'Bad', '', '', '', '', '', '']]);

    $dry = runImport($file, dry: true);
    expect(Product::count())->toBe(0)->and(DB::table('prices')->count())->toBe(0)->and(DB::table('stock_movements')->count())->toBe(0)->and(DB::table('audit_events')->where('action', 'like', 'product.%')->count())->toBe(0);

    $real = runImport($file);
    expect([$dry->created, $dry->updated, $dry->failed])->toBe([$real->created, $real->updated, $real->failed])->and(Product::count())->toBe(2);
});

it('refuses unusable files as a whole', function () {
    expect(fn () => runImport(csvFile([['a']], ['sku'])))->toThrow(ImportFailed::class);

    $bad = tempnam(sys_get_temp_dir(), 'csv');
    file_put_contents($bad, "sku,product_type,name_en\nA-1,physical,\xE0\xE1\xE2\n");
    expect(fn () => runImport($bad))->toThrow(ImportFailed::class);
});

it('will not touch a product that has several variants', function () {
    runImport(csvFile([GOOD_ROWS[0]]));
    $product = ProductVariant::where('sku', 'TEE-1')->firstOrFail()->product;
    $size = app(SaveAttribute::class)->handle($this->actor, null, 'size', 'option', false, ['en' => 'Size'], [['code' => 's', 'labels' => ['en' => 'S']], ['code' => 'm', 'labels' => ['en' => 'M']]]);
    [$s, $m] = $size->values->pluck('id')->map(fn ($v) => (int) $v)->all();
    $save = app(SaveVariant::class);
    $save->handle($this->actor, $product, $product->variants()->firstOrFail(), new VariantData('TEE-1', trackInventory: true, isDefault: true, optionValueIds: [$s]));
    $save->handle($this->actor, $product, null, new VariantData('TEE-1-B', optionValueIds: [$m]));

    $report = runImport(csvFile([['TEE-1', 'physical', 'Changed', '', '', '', '', '', '']]));

    expect($report->rows[0]['errors'][0]['key'])->toBe('not_simple')->and($product->fresh()->translate('name', 'en'))->toBe('Cotton Tee');
});

it('rolls back a whole chunk when something unexpected fails, and reports every row in it', function () {
    app()->bind(StockInitialiser::class, fn () => new class implements StockInitialiser
    {
        public function openingStock(Actor $actor, int $variantId, int $quantity, string $locationCode = 'main'): bool
        {
            throw new RuntimeException('disk on fire');
        }
    });

    $report = runImport(csvFile([GOOD_ROWS[0], ['TEE-2', 'physical', 'Second', '', '', '', '', '', '']]));

    expect($report->failed)->toBe(2)->and($report->created)->toBe(0)
        ->and(collect($report->rows)->pluck('errors.0.key')->unique()->all())->toBe(['chunk_failed'])
        ->and(Product::count())->toBe(0);
});

it('runs through the screens: upload, background check, apply, export and round-trip', function () {
    Storage::fake('private');
    Queue::fake();
    $staff = catalogStaff(['catalog.view', 'catalog.create']);
    $upload = fn (bool $dry) => UploadedFile::fake()->createWithContent('products.csv', file_get_contents(csvFile(GOOD_ROWS)));

    $this->actingAs($staff, 'admin')->post('/admin/catalog/import-export', ['file' => $upload(true), 'dry_run' => '1'])->assertRedirect();
    Queue::assertPushed(ProcessCatalogImport::class);

    $check = CatalogImport::firstOrFail();
    expect($check->status)->toBe('queued')->and($check->dry_run)->toBeTrue();
    (new ProcessCatalogImport($check->id))->handle(app(ProductCsvImporter::class));

    expect($check->fresh()->status)->toBe('done')->and($check->fresh()->created_count)->toBe(2)->and(Product::count())->toBe(0);
    $this->actingAs($staff, 'admin')->get("/admin/catalog/import-export/{$check->public_id}")->assertOk()->assertSee('products.csv');

    $this->actingAs($staff, 'admin')->post("/admin/catalog/import-export/{$check->public_id}/apply")->assertRedirect();
    $apply = CatalogImport::query()->where('dry_run', false)->firstOrFail();
    (new ProcessCatalogImport($apply->id))->handle(app(ProductCsvImporter::class));
    expect(Product::count())->toBe(2)->and($apply->fresh()->status)->toBe('done');
    Storage::disk('private')->assertMissing($apply->stored_path); // the uploaded file is removed once applied

    $csv = $this->actingAs($staff, 'admin')->get('/admin/catalog/import-export/export')->assertOk()->streamedContent();
    expect($csv)->toStartWith("\xEF\xBB\xBFsku,")->toContain('TEE-1')->toContain('সুতির টি-শার্ট');

    $path = tempnam(sys_get_temp_dir(), 'rt');
    file_put_contents($path, $csv);
    $round = runImport($path);
    expect([$round->created, $round->failed])->toBe([0, 0])->and($round->updated)->toBe(2); // exported file imports cleanly (extra columns ignored)
});

it('neutralises spreadsheet formulas in exports', function () {
    expect(CsvWriter::neutralise('=HYPERLINK("http://evil")'))->toStartWith("'=")
        ->and(CsvWriter::neutralise('+1'))->toStartWith("'")
        ->and(CsvWriter::neutralise('@cmd'))->toStartWith("'")
        ->and(CsvWriter::neutralise('Plain'))->toBe('Plain')
        ->and(CsvWriter::neutralise('বাংলা'))->toBe('বাংলা');

    runImport(csvFile([['F-1', 'physical', '=1+1', '', '', '', '', '', '']]));
    $csv = $this->actingAs(catalogStaff(['catalog.view']), 'admin')->get('/admin/catalog/import-export/export')->streamedContent();
    expect($csv)->toContain("'=1+1");
});

it('gates import and export by permission and rejects non-CSV uploads', function () {
    Storage::fake('private');
    Queue::fake();

    $this->actingAs(catalogStaff(['catalog.view']), 'admin')->post('/admin/catalog/import-export', ['file' => UploadedFile::fake()->createWithContent('p.csv', 'sku')])->assertForbidden();
    $this->actingAs(catalogStaff(['pricing.view']), 'admin')->get('/admin/catalog/import-export')->assertForbidden();
    $this->actingAs(catalogStaff(['catalog.create']), 'admin')->post('/admin/catalog/import-export', ['file' => UploadedFile::fake()->create('p.exe', 10, 'application/octet-stream')])->assertSessionHasErrors('file');
    Queue::assertNothingPushed();
});
