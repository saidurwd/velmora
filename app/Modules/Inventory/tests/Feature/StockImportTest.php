<?php

declare(strict_types=1);

use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\IdentityAccess\Models\User;
use App\Modules\Inventory\Actions\AdjustStock;
use App\Modules\Inventory\Actions\PreviewStockImport;
use App\Modules\Inventory\Contracts\StockReservations;
use App\Modules\Inventory\Exceptions\StockOpsException;
use App\Modules\Inventory\Services\StockLedgerVerifier;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/*
 * Phase 10, slice 5b: stock files. A CSV is read first (a dry run with an error report), applied once through the same AdjustStock as the single-product form, and can be
 * undone with a compensating batch. Two products start with 50 each in "main".
 */

beforeEach(function () {
    shippingWorld($this);
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    RateLimiter::clear('inventory-actions');
    $this->boss = catalogStaff(['inventory.view', 'inventory.adjust']);
    $this->viewer = catalogStaff(['inventory.view']);
    $this->as = fn (User $u) => $this->flushSession()->actingAs($u, 'admin');
    $this->actor = new Actor(ActorType::Staff, $this->boss->id, null, 'Boss');
    $this->level = fn (int $variantId, string $code = 'main') => DB::table('stock_levels')->join('stock_locations', 'stock_locations.id', '=', 'stock_levels.location_id')->where('variant_id', $variantId)->where('stock_locations.code', $code)->first(['on_hand', 'reserved']);
    $this->csv = fn (string $content, string $name = 'stock.csv') => UploadedFile::fake()->createWithContent($name, $content);
    $this->read = fn (string $content, array $over = [], ?User $by = null) => ($this->as)($by ?? $this->boss)->post('/admin/inventory/imports', $over + ['file' => ($this->csv)($content), 'location' => 'main', 'mode' => 'set', 'note' => 'Yearly count']);
    $this->latest = fn () => DB::table('stock_imports')->orderByDesc('id')->first();
    $this->apply = fn (string $publicId, ?User $by = null) => ($this->as)($by ?? $this->boss)->post("/admin/inventory/imports/{$publicId}/apply");
    $this->undo = fn (string $publicId, ?User $by = null) => ($this->as)($by ?? $this->boss)->post("/admin/inventory/imports/{$publicId}/rollback");
    $this->importMoves = fn (string $type = 'stock_import') => DB::table('stock_movements')->where('ref_type', $type)->count();
    /** A file already read and ready to apply; returns the import's public id. */
    $this->ready = function (string $content, string $mode = 'set'): string {
        ($this->read)($content, ['mode' => $mode])->assertRedirect();

        return (string) ($this->latest)()->public_id;
    };
});

describe('reading a file (the dry run)', function () {
    it('shows what would change and changes nothing', function () {
        $before = DB::table('stock_movements')->count();

        ($this->read)("sku,quantity\nTEE-1,60\nMUG-1,50\n")->assertRedirect();
        $import = ($this->latest)();

        expect($import->status)->toBe('previewed')->and($import->row_count)->toBe(2)->and($import->error_count)->toBe(0)->and($import->unchanged_count)->toBe(1)->and($import->note)->toBe('Yearly count')
            ->and(($this->level)($this->tee)->on_hand)->toBe(50)->and(DB::table('stock_movements')->count())->toBe($before);
        $rows = DB::table('stock_import_rows')->where('import_id', $import->id)->orderBy('line')->get();
        expect($rows->pluck('status')->all())->toBe(['ok', 'unchanged'])->and($rows[0]->before_on_hand)->toBe(50)->and($rows[0]->delta)->toBe(10)->and($rows[0]->applied_delta)->toBeNull();
        ($this->as)($this->boss)->get('/admin/inventory/imports/'.$import->public_id)->assertOk()->assertSee('Nothing has been changed yet')->assertSee('1 rows will change stock, 1 are already right, 0 have problems.')->assertSee('TEE-1');
    });

    it('reads "add" files as changes, a minus taking stock away', function () {
        ($this->read)("sku,quantity\nTEE-1,5\nMUG-1,-3\n", ['mode' => 'add']);

        $rows = DB::table('stock_import_rows')->where('import_id', ($this->latest)()->id)->orderBy('line')->get();
        expect($rows->pluck('delta')->all())->toBe([5, -3])->and($rows->pluck('status')->unique()->all())->toBe(['ok']);
    });

    it('reports every problem by line, and a file with problems cannot be applied', function () {
        app(StockReservations::class)->holdForOrder([$this->tee => 45], 'HOLD-1');
        DB::table('product_variants')->where('id', $this->mug)->update(['track_inventory' => false]);
        $csv = "sku,quantity\nTEE-1,10\nNOPE-9,5\nTEE-1,7\n,4\nMUG-1,5\nTEE-2,abc\n";
        $csv .= "TEE-3,-2\n";

        ($this->read)($csv, ['mode' => 'set'])->assertRedirect();
        $import = ($this->latest)();
        $rows = DB::table('stock_import_rows')->where('import_id', $import->id)->orderBy('line')->get()->keyBy('line');

        expect($rows[2]->error_code)->toBe('below_reserved')->and($rows[3]->error_code)->toBe('sku_unknown')->and($rows[4]->error_code)->toBe('duplicate_sku')->and($rows[5]->error_code)->toBe('sku_missing')
            ->and($rows[6]->error_code)->toBe('not_tracked')->and($rows[7]->error_code)->toBe('sku_unknown')->and($rows[8]->error_code)->toBe('sku_unknown')->and($import->error_count)->toBe(7);

        ($this->apply)($import->public_id)->assertSessionHasErrors('import');
        expect(($this->level)($this->tee)->on_hand)->toBe(50)->and(($this->latest)()->status)->toBe('previewed');
        ($this->as)($this->boss)->get('/admin/inventory/imports/'.$import->public_id)->assertOk()->assertSee('Fix the problems below')->assertSee('No product has this SKU.')->assertSee('This SKU appears twice in the file.');
    });

    it('reports a bad quantity and a negative "set" quantity on their own lines', function () {
        ($this->read)("sku,quantity\nTEE-1,abc\nMUG-1,-4\n", ['mode' => 'set']);

        $rows = DB::table('stock_import_rows')->where('import_id', ($this->latest)()->id)->orderBy('line')->get();
        expect($rows[0]->error_code)->toBe('quantity_invalid')->and($rows[0]->raw_quantity)->toBe('abc')->and($rows[1]->error_code)->toBe('quantity_negative');
    });

    it('refuses a quantity too large to store, and a place that is switched off', function () {
        ($this->read)("sku,quantity\nTEE-1,99999999999\n");
        expect(DB::table('stock_import_rows')->where('import_id', ($this->latest)()->id)->value('error_code'))->toBe('quantity_invalid');

        DB::table('stock_locations')->insert(['public_id' => strtolower((string) Str::ulid()), 'code' => 'shop', 'name' => 'Shop', 'type' => 'store', 'is_active' => false, 'created_at' => now(), 'updated_at' => now()]);
        ($this->read)("sku,quantity\nTEE-1,1\n", ['location' => 'shop'])->assertSessionHasErrors('import');
        expect(DB::table('stock_imports')->count())->toBe(1);
    });

    it('asks for the reason even when the Action is called directly', function () {
        $path = tempnam(sys_get_temp_dir(), 'stk');
        file_put_contents($path, "sku,quantity\nTEE-1,60\n");

        try {
            expect(fn () => app(PreviewStockImport::class)->handle($this->actor, $path, 'f.csv', 'main', 'set', '   '))->toThrow(StockOpsException::class, 'note_required');
        } finally {
            @unlink($path);
        }
        expect(DB::table('stock_imports')->count())->toBe(0);
    });

    it('gives the problems back as a file to fix', function () {
        ($this->read)("sku,quantity\nTEE-1,60\nNOPE-9,5\n");
        $import = ($this->latest)();

        $response = ($this->as)($this->boss)->get("/admin/inventory/imports/{$import->public_id}/problems")->assertOk();
        $text = $response->streamedContent();

        expect($response->headers->get('content-type'))->toContain('text/csv')->and($text)->toContain('line,sku,quantity,problem')->and($text)->toContain('NOPE-9')->and($text)->toContain('No product has this SKU.')->and($text)->not->toContain('TEE-1');
    });

    it('understands Bangla digits and keeps SKUs as written', function () {
        ($this->read)("sku,quantity\nTEE-1,৬০\n");

        $row = DB::table('stock_import_rows')->where('import_id', ($this->latest)()->id)->first();
        expect($row->status)->toBe('ok')->and($row->quantity)->toBe(60);
    });

    it('refuses a file it cannot use, with a clear reason and nothing stored', function () {
        ($this->read)("sku,amount\nTEE-1,60\n")->assertSessionHasErrors('import');
        ($this->read)("sku,quantity\n")->assertSessionHasErrors('import');
        ($this->read)('')->assertSessionHasErrors('import');
        ($this->read)("sku,quantity\nTEE-\xFF,60\n")->assertSessionHasErrors('import');   // not UTF-8
        ($this->read)("sku,quantity\nTEE-1,60\n", ['note' => '   '])->assertSessionHasErrors('note');
        ($this->read)("sku,quantity\nTEE-1,60\n", ['location' => 'nowhere'])->assertSessionHasErrors('import');
        ($this->read)("sku,quantity\nTEE-1,60\n", ['mode' => 'delete'])->assertSessionHasErrors('mode');
        ($this->read)('x', ['file' => ($this->csv)("sku,quantity\nTEE-1,60\n", 'stock.exe')])->assertSessionHasErrors('file');
        expect(DB::table('stock_imports')->count())->toBe(0);
    });

    it('takes only as many rows as the limit allows', function () {
        config(['inventory.imports.max_rows' => 2]);

        ($this->read)("sku,quantity\nTEE-1,1\nMUG-1,2\nTEE-1,3\n")->assertSessionHasErrors('import');
        expect(DB::table('stock_imports')->count())->toBe(0);
        ($this->read)("sku,quantity\nTEE-1,1\nMUG-1,2\n")->assertRedirect();
        expect(DB::table('stock_imports')->count())->toBe(1);
    });
});

describe('applying a file', function () {
    it('changes stock through the ledger, once, with a record of who and why', function () {
        $id = ($this->ready)("sku,quantity\nTEE-1,60\nMUG-1,45\n");

        ($this->apply)($id)->assertRedirect()->assertSessionHas('status');

        expect(($this->level)($this->tee)->on_hand)->toBe(60)->and(($this->level)($this->mug)->on_hand)->toBe(45)->and(($this->importMoves)())->toBe(2)
            ->and(DB::table('stock_movements')->where('ref_type', 'stock_import')->where('ref_id', $id)->where('reason', 'adjustment')->where('note', 'Yearly count')->where('created_by', $this->boss->id)->count())->toBe(2)
            ->and(DB::table('stock_movements')->where('ref_id', $id)->sum('delta'))->toEqual(5)
            ->and(app(StockLedgerVerifier::class)->verify())->toBe([]);
        $import = ($this->latest)();
        expect($import->status)->toBe('applied')->and($import->applied_by)->toBe($this->boss->id)->and($import->applied_at)->not->toBeNull()
            ->and(DB::table('stock_import_rows')->where('import_id', $import->id)->orderBy('line')->pluck('applied_delta')->all())->toBe([10, -5])
            ->and(DB::table('stock_import_rows')->where('import_id', $import->id)->orderBy('line')->pluck('after_on_hand')->all())->toBe([60, 45])
            ->and(DB::table('audit_events')->where('action', 'stock.import_applied')->count())->toBe(1);
    });

    it('works "set" out against the stock as it is now, not as it was at the preview', function () {
        $id = ($this->ready)("sku,quantity\nTEE-1,60\n");
        app(AdjustStock::class)->handle($this->actor, $this->tee, 'main', 7, 'receipt');   // stock moved after the preview

        ($this->apply)($id)->assertSessionHas('status');

        expect(($this->level)($this->tee)->on_hand)->toBe(60)->and(DB::table('stock_movements')->where('ref_id', $id)->value('delta'))->toBe(3);
    });

    it('applies "add" rows as the change they are', function () {
        $id = ($this->ready)("sku,quantity\nTEE-1,5\nMUG-1,-3\n", 'add');

        ($this->apply)($id);

        expect(($this->level)($this->tee)->on_hand)->toBe(55)->and(($this->level)($this->mug)->on_hand)->toBe(47);
    });

    it('does nothing at all if one row no longer fits, and says which', function () {
        $id = ($this->ready)("sku,quantity\nMUG-1,60\nTEE-1,-40\n", 'add');
        app(StockReservations::class)->holdForOrder([$this->tee => 20], 'HOLD-2');   // after the preview: taking 40 from 50 would leave less than the 20 promised

        ($this->apply)($id)->assertSessionHasErrors('import');

        expect(($this->level)($this->tee)->on_hand)->toBe(50)->and(($this->level)($this->mug)->on_hand)->toBe(50)->and(($this->importMoves)())->toBe(0)->and(($this->latest)()->status)->toBe('previewed')
            ->and(DB::table('stock_import_rows')->whereNotNull('applied_delta')->count())->toBe(0);
        ($this->as)($this->boss)->get('/admin/inventory/imports/'.$id)->assertOk()->assertSee('Apply the file');   // still waiting: nothing was used up
    });

    it('cannot be applied twice', function () {
        $id = ($this->ready)("sku,quantity\nTEE-1,60\n");

        ($this->apply)($id)->assertSessionHas('status');
        ($this->apply)($id)->assertSessionHasErrors('import');

        expect(($this->level)($this->tee)->on_hand)->toBe(60)->and(($this->importMoves)())->toBe(1);
    });

    it('cannot be applied once the preview has gone stale', function () {
        $id = ($this->ready)("sku,quantity\nTEE-1,60\n");

        $this->travel(25)->hours();
        ($this->apply)($id)->assertSessionHasErrors('import');
        expect(($this->level)($this->tee)->on_hand)->toBe(50);

        $this->travelBack();
        ($this->apply)($id)->assertSessionHas('status');
    });

    it('does not apply a file that does not exist', function () {
        ($this->apply)(strtolower((string) Str::ulid()))->assertNotFound();
    });
});

describe('undoing a file', function () {
    it('puts stock back with new ledger rows and never deletes one', function () {
        $id = ($this->ready)("sku,quantity\nTEE-1,60\nMUG-1,45\n");
        ($this->apply)($id);
        $movements = DB::table('stock_movements')->count();

        ($this->undo)($id)->assertSessionHas('status');

        expect(($this->level)($this->tee)->on_hand)->toBe(50)->and(($this->level)($this->mug)->on_hand)->toBe(50)->and(DB::table('stock_movements')->count())->toBe($movements + 2)
            ->and(DB::table('stock_movements')->where('ref_type', 'stock_import')->count())->toBe(2)->and(($this->importMoves)('stock_import_rollback'))->toBe(2)
            ->and(app(StockLedgerVerifier::class)->verify())->toBe([]);
        $import = ($this->latest)();
        expect($import->status)->toBe('rolled_back')->and($import->rolled_back_by)->toBe($this->boss->id)
            ->and(DB::table('stock_import_rows')->where('import_id', $import->id)->pluck('rollback_state')->unique()->all())->toBe(['reverted'])
            ->and(DB::table('audit_events')->where('action', 'stock.import_rolled_back')->count())->toBe(1);
    });

    it('leaves alone a product whose stock has changed since, and undoes the rest', function () {
        $id = ($this->ready)("sku,quantity\nTEE-1,60\nMUG-1,45\n");
        ($this->apply)($id);
        app(AdjustStock::class)->handle($this->actor, $this->tee, 'main', 4, 'receipt');   // someone received tees after the import

        ($this->undo)($id)->assertSessionHas('status');

        expect(($this->level)($this->tee)->on_hand)->toBe(64)->and(($this->level)($this->mug)->on_hand)->toBe(50);
        $states = DB::table('stock_import_rows')->where('import_id', ($this->latest)()->id)->orderBy('line')->pluck('rollback_state')->all();
        expect($states)->toBe(['kept_changed', 'reverted']);
    });

    it('leaves alone a product whose undoing would take stock below what orders are promised', function () {
        $id = ($this->ready)("sku,quantity\nTEE-1,70\n");
        ($this->apply)($id);
        app(StockReservations::class)->holdForOrder([$this->tee => 65], 'HOLD-3');   // the extra 20 is now promised to orders

        ($this->undo)($id)->assertSessionHas('status');

        expect(($this->level)($this->tee)->on_hand)->toBe(70)->and(DB::table('stock_import_rows')->where('import_id', ($this->latest)()->id)->value('rollback_state'))->toBe('kept_blocked');
    });

    it('can be done once, and only for a file that was applied', function () {
        $id = ($this->ready)("sku,quantity\nTEE-1,60\n");

        ($this->undo)($id)->assertSessionHasErrors('import');   // not applied yet
        ($this->apply)($id);
        ($this->undo)($id)->assertSessionHas('status');
        ($this->undo)($id)->assertSessionHasErrors('import');
        ($this->apply)($id)->assertSessionHasErrors('import');   // and it cannot be applied again either

        expect(($this->level)($this->tee)->on_hand)->toBe(50)->and(($this->importMoves)('stock_import_rollback'))->toBe(1);
    });
});

describe('who may', function () {
    it('is only for staff who may adjust stock', function () {
        $id = ($this->ready)("sku,quantity\nTEE-1,60\n");

        ($this->as)($this->viewer)->get('/admin/inventory/imports')->assertForbidden();
        ($this->as)($this->viewer)->get("/admin/inventory/imports/{$id}")->assertForbidden();
        ($this->as)($this->viewer)->get("/admin/inventory/imports/{$id}/problems")->assertForbidden();
        ($this->read)("sku,quantity\nTEE-1,60\n", [], $this->viewer)->assertForbidden();
        ($this->apply)($id, $this->viewer)->assertForbidden();
        ($this->undo)($id, $this->viewer)->assertForbidden();
        expect(($this->level)($this->tee)->on_hand)->toBe(50)->and(DB::table('stock_imports')->count())->toBe(1);

        auth()->forgetGuards();
        $this->flushSession()->get('/admin/inventory/imports')->assertRedirect('/admin/login');
        ($this->as)($this->boss)->get('/admin/inventory/imports')->assertOk()->assertSee('Stock files')->assertSee('stock.csv');
    });

    it('is limited in how often it can be used', function () {
        $id = ($this->ready)("sku,quantity\nTEE-1,60\n");

        foreach (range(1, 60) as $i) {
            ($this->as)($this->boss)->get("/admin/inventory/imports/{$id}");
        }
        ($this->as)($this->boss)->get("/admin/inventory/imports/{$id}")->assertStatus(429);
    });

    it('renders in Bangla', function () {
        $id = ($this->ready)("sku,quantity\nTEE-1,60\nNOPE-9,1\n");

        ($this->as)($this->boss)->withSession(['locale' => 'bn'])->get("/admin/inventory/imports/{$id}")->assertOk();
        expect(trans('inventory::imports.title', [], 'bn'))->toBe('স্টকের ফাইল')->and(trans('inventory::ops.errors.row_sku_unknown', [], 'bn'))->toBe('এই SKU-র কোনো পণ্য নেই।');
    });
});

describe('what the database itself refuses', function () {
    it('never lets a file go back to a state it was in, or be deleted, or its rows be rewritten', function () {
        $id = ($this->ready)("sku,quantity\nTEE-1,60\n");
        ($this->apply)($id);
        $import = ($this->latest)();
        $row = DB::table('stock_import_rows')->where('import_id', $import->id)->first();

        expect(fn () => DB::table('stock_imports')->where('id', $import->id)->update(['status' => 'previewed']))->toThrow(QueryException::class)
            ->and(fn () => DB::table('stock_imports')->where('id', $import->id)->update(['note' => 'changed']))->toThrow(QueryException::class)
            ->and(fn () => DB::table('stock_imports')->where('id', $import->id)->delete())->toThrow(QueryException::class)
            ->and(fn () => DB::table('stock_import_rows')->where('id', $row->id)->update(['quantity' => 999]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('stock_import_rows')->where('id', $row->id)->delete())->toThrow(QueryException::class)
            ->and(fn () => DB::table('stock_imports')->where('id', $import->id)->update(['status' => 'rolled_back', 'updated_at' => now()]))->not->toThrow(QueryException::class);
    });

    it('holds a file and its rows to their rules', function () {
        $now = now();
        $base = ['public_id' => strtolower((string) Str::ulid()), 'location_id' => DB::table('stock_locations')->value('id'), 'mode' => 'set', 'status' => 'previewed', 'note' => 'x', 'row_count' => 1, 'created_at' => $now, 'updated_at' => $now];

        expect(fn () => DB::table('stock_imports')->insert(['mode' => 'xx'] + $base))->toThrow(QueryException::class, 'stock_imports_check')
            ->and(fn () => DB::table('stock_imports')->insert(['status' => 'done', 'public_id' => strtolower((string) Str::ulid())] + $base))->toThrow(QueryException::class, 'stock_imports_check')
            ->and(fn () => DB::table('stock_imports')->insert(['error_count' => 2, 'public_id' => strtolower((string) Str::ulid())] + $base))->toThrow(QueryException::class, 'stock_imports_check');

        $importId = DB::table('stock_imports')->insertGetId($base);
        $row = ['import_id' => $importId, 'line' => 2, 'sku' => 'TEE-1', 'status' => 'ok'];
        DB::table('stock_import_rows')->insert($row);
        expect(fn () => DB::table('stock_import_rows')->insert($row))->toThrow(QueryException::class)   // one row per line
            ->and(fn () => DB::table('stock_import_rows')->insert(['line' => 3, 'status' => 'error'] + $row))->toThrow(QueryException::class, 'stock_import_rows_check')   // an error needs its code
            ->and(fn () => DB::table('stock_import_rows')->insert(['line' => 4, 'status' => 'maybe'] + $row))->toThrow(QueryException::class, 'stock_import_rows_check');
    });
});
