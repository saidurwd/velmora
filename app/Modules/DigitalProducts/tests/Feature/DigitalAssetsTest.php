<?php

declare(strict_types=1);

use App\Modules\Catalog\Actions\SaveCategory;
use App\Modules\Catalog\Actions\SaveProduct;
use App\Modules\Catalog\DTOs\ProductData;
use App\Modules\Catalog\DTOs\TranslationInput;
use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\DigitalProducts\Actions\UploadDigitalAsset;
use App\Modules\DigitalProducts\Exceptions\DigitalException;
use App\Modules\IdentityAccess\Database\Seeders\RolesAndPermissionsSeeder;
use App\Modules\IdentityAccess\Http\Middleware\EnforceSessionPolicy;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Pricing\Database\Seeders\TaxClassSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed([LanguageSeeder::class, TaxClassSeeder::class, RolesAndPermissionsSeeder::class]);
    $this->withoutMiddleware(EnforceSessionPolicy::class);
    Storage::fake('private');
    config(['digital.disk' => 'private']);
    $system = Actor::system('t');
    $category = app(SaveCategory::class)->handle($system, null, null, [new TranslationInput('en', 'General')]);
    $make = fn (string $sku, string $name, ProductType $type) => app(SaveProduct::class)->handle($system, null, new ProductData($type, [new TranslationInput('en', $name), new TranslationInput('bn', $name)], categoryIds: [$category->id], defaultVariant: new VariantData($sku)));
    $make('BOOK-1', 'Cooking e-book', ProductType::Digital);
    $make('TEE-1', 'Cotton Tee', ProductType::Physical);
    $this->staffUser = catalogStaff(['digital.view', 'digital.manage']);
    $this->staff = new Actor(ActorType::Staff, $this->staffUser->id, null, 'Amina');
    $this->viewer = catalogStaff(['digital.view']);
    $this->nobody = catalogStaff(['catalog.view']);
    $this->as = fn ($user) => $this->actingAs($user, 'admin');

    // real file contents, so the check of the bytes has something to read
    $this->tmp = [];
    $this->file = function (string $kind, string $name = 'file') {
        $path = tempnam(sys_get_temp_dir(), 'dg');
        $this->tmp[] = $path;
        match ($kind) {
            'pdf' => file_put_contents($path, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n"),
            'png' => file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==')),
            'zip' => (function () use ($path) {
                $zip = new ZipArchive;
                $zip->open($path, ZipArchive::OVERWRITE);
                $zip->addFromString('a.txt', 'hello');
                $zip->close();
            })(),
            'epub' => (function () use ($path) {
                $zip = new ZipArchive;
                $zip->open($path, ZipArchive::OVERWRITE);
                $zip->addFromString('mimetype', 'application/epub+zip');
                $zip->setCompressionName('mimetype', ZipArchive::CM_STORE);
                $zip->addFromString('META-INF/container.xml', '<container/>');
                $zip->close();
            })(),
            'exe' => file_put_contents($path, "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF".str_repeat("\x00", 200)),
            'empty' => file_put_contents($path, ''),
            default => file_put_contents($path, $kind),
        };

        return $path;
    };
    $this->upload = fn (string $kind, string $name, string $sku = 'BOOK-1') => app(UploadDigitalAsset::class)->handle($this->staff, $sku, ($this->file)($kind), $name);
    $this->refuses = fn (callable $call, string $reason) => expect($call)->toThrow(DigitalException::class, $reason);
});

afterEach(function () {
    foreach ($this->tmp ?? [] as $path) {
        @unlink($path);
    }
});

describe('putting a file in', function () {
    it('stores it in the private bucket under a name we made, with its size and a checksum', function () {
        $version = ($this->upload)('pdf', 'Cooking Book.pdf');

        expect($version->version)->toBe(1)->and($version->mime)->toBe('application/pdf')->and($version->original_filename)->toBe('Cooking Book.pdf')->and($version->storage_key)->toStartWith('digital/')
            ->and($version->storage_key)->not->toContain('Cooking')->and($version->storage_key)->not->toContain('.pdf')->and($version->sha256)->toBe(hash('sha256', Storage::disk('private')->get($version->storage_key)))
            ->and($version->size_bytes)->toBe(Storage::disk('private')->size($version->storage_key));
        Storage::disk('private')->assertExists($version->storage_key);
        expect(DB::table('audit_events')->where('action', 'digital.asset_uploaded')->count())->toBe(1);
    });

    it('accepts each allowed kind when its content is what its name says', function () {
        foreach (['pdf' => 'a.pdf', 'png' => 'a.png', 'zip' => 'a.zip', 'epub' => 'a.epub'] as $kind => $name) {
            expect(($this->upload)($kind, $name)->original_filename)->toBe($name);
        }
        expect(DB::table('digital_asset_versions')->count())->toBe(4)->and(DB::table('digital_assets')->count())->toBe(1);
    });

    it('makes each upload a new version, keeps the old ones, and never reuses a number', function () {
        $one = ($this->upload)('pdf', 'v1.pdf');
        $two = ($this->upload)('pdf', 'v2.pdf');

        expect([$one->version, $two->version])->toBe([1, 2])->and($one->storage_key)->not->toBe($two->storage_key);
        Storage::disk('private')->assertExists($one->storage_key);
        expect(fn () => DB::table('digital_asset_versions')->insert(['asset_id' => $one->asset_id, 'version' => 2, 'storage_key' => 'x', 'original_filename' => 'x', 'mime' => 'x', 'size_bytes' => 1, 'sha256' => str_repeat('a', 64), 'created_at' => now()]))->toThrow(QueryException::class);
    });

    it('keeps the history: a version can be neither changed nor removed', function () {
        $version = ($this->upload)('pdf', 'v1.pdf');

        expect(fn () => DB::table('digital_asset_versions')->where('id', $version->id)->update(['sha256' => str_repeat('b', 64)]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('digital_asset_versions')->where('id', $version->id)->delete())->toThrow(QueryException::class);
    });
});

describe('what is refused', function () {
    it('refuses a file whose content is not what its name says, and stores nothing', function () {
        ($this->refuses)(fn () => ($this->upload)('exe', 'Cooking Book.pdf'), 'content_mismatch');
        ($this->refuses)(fn () => ($this->upload)('png', 'Cooking Book.pdf'), 'content_mismatch');
        ($this->refuses)(fn () => ($this->upload)('pdf', 'Cooking Book.zip'), 'content_mismatch');

        expect(Storage::disk('private')->allFiles())->toBe([])->and(DB::table('digital_asset_versions')->count())->toBe(0)->and(DB::table('digital_assets')->count())->toBe(0);
    });

    it('refuses kinds that are not allowed, even with a harmless name or a double extension', function () {
        ($this->refuses)(fn () => ($this->upload)('exe', 'setup.exe'), 'type_not_allowed');
        ($this->refuses)(fn () => ($this->upload)('<?php echo 1;', 'shell.php'), 'type_not_allowed');
        ($this->refuses)(fn () => ($this->upload)('pdf', 'book.pdf.php'), 'type_not_allowed');
        ($this->refuses)(fn () => ($this->upload)('pdf', 'noextension'), 'type_not_allowed');
        expect(Storage::disk('private')->allFiles())->toBe([]);
    });

    it('refuses an empty file, a file over the limit, an unknown product and a product that is not digital', function () {
        ($this->refuses)(fn () => ($this->upload)('empty', 'a.pdf'), 'file_empty');
        config(['digital.max_upload_mb' => 0]);
        ($this->refuses)(fn () => ($this->upload)('pdf', 'a.pdf'), 'file_too_large');
        config(['digital.max_upload_mb' => 200]);
        ($this->refuses)(fn () => ($this->upload)('pdf', 'a.pdf', 'NOPE-9'), 'variant_unknown');
        ($this->refuses)(fn () => ($this->upload)('pdf', 'a.pdf', 'TEE-1'), 'variant_not_digital');
        expect(DB::table('digital_assets')->count())->toBe(0);
    });

    it('cleans the name the customer will see: no folders, no control characters', function () {
        $version = ($this->upload)('pdf', "../../etc/\x00pass\"wd/My Book.pdf");

        expect($version->original_filename)->toBe('My Book.pdf');
        $version = ($this->upload)('pdf', 'C:\\Users\\me\\Recipes.pdf');
        expect($version->original_filename)->toBe('Recipes.pdf');
    });

    it('leaves no file behind when the record cannot be written', function () {
        $first = ($this->upload)('pdf', 'v1.pdf');
        $before = Storage::disk('private')->allFiles();
        $this->mock(Clock::class, fn ($mock) => $mock->shouldReceive('now')->andThrow(new RuntimeException('the record could not be written')));   // the Action asks for the time only inside its transaction, after the file was stored

        expect(fn () => ($this->upload)('pdf', 'v2.pdf'))->toThrow(RuntimeException::class)->and(DB::table('digital_asset_versions')->count())->toBe(1);

        expect(Storage::disk('private')->allFiles())->toBe($before);
    });
});

describe('the staff screens', function () {
    it('keeps them away from guests and staff without the permission, and lets a viewer look but not upload', function () {
        $this->get('/admin/digital/assets')->assertRedirect();
        ($this->as)($this->nobody)->get('/admin/digital/assets')->assertForbidden();
        ($this->as)($this->nobody)->get('/admin/digital/assets/BOOK-1')->assertForbidden();
        ($this->as)($this->viewer)->get('/admin/digital/assets')->assertOk();
        ($this->as)($this->viewer)->get('/admin/digital/assets/BOOK-1')->assertOk()->assertDontSee('Upload a new version');
        ($this->as)($this->viewer)->post('/admin/digital/assets/BOOK-1', ['file' => UploadedFile::fake()->create('a.pdf', 10)])->assertForbidden();
        ($this->as)($this->nobody)->post('/admin/digital/assets/BOOK-1', ['file' => UploadedFile::fake()->create('a.pdf', 10)])->assertForbidden();
        expect(DB::table('digital_assets')->count())->toBe(0);
    });

    it('finds digital products only, and uploads through the page, showing the history with the checksum', function () {
        ($this->as)($this->staffUser)->get('/admin/digital/assets?q=BOOK')->assertOk()->assertSee('BOOK-1')->assertSee('Cooking e-book');
        ($this->as)($this->staffUser)->get('/admin/digital/assets?q=TEE')->assertOk()->assertDontSee('TEE-1')->assertSee('No matching digital product.');
        ($this->as)($this->staffUser)->get('/admin/digital/assets/TEE-1')->assertNotFound();
        ($this->as)($this->staffUser)->get('/admin/digital/assets/NOPE')->assertNotFound();

        $path = ($this->file)('pdf');
        $upload = new UploadedFile($path, 'Cooking Book.pdf', 'application/pdf', null, true);
        ($this->as)($this->staffUser)->post('/admin/digital/assets/BOOK-1', ['file' => $upload])->assertSessionHas('status', 'Version 1 was uploaded.');

        $sha = DB::table('digital_asset_versions')->value('sha256');
        ($this->as)($this->staffUser)->get('/admin/digital/assets/BOOK-1')->assertOk()->assertSee('Cooking Book.pdf')->assertSee($sha)->assertSee('Current')->assertDontSee(DB::table('digital_asset_versions')->value('storage_key'));   // the storage key is never shown
    });

    it('explains a refusal on the page, and lies about nothing when the file claims to be something else', function () {
        $path = ($this->file)('exe');
        $fake = new UploadedFile($path, 'Cooking Book.pdf', 'application/pdf', null, true);   // the browser says it is a PDF; the bytes say it is not

        ($this->as)($this->staffUser)->post('/admin/digital/assets/BOOK-1', ['file' => $fake])->assertSessionHasErrors(['asset' => 'The content of the file does not match its name. Nothing was stored.']);
        ($this->as)($this->staffUser)->post('/admin/digital/assets/BOOK-1', [])->assertSessionHasErrors('file');
        expect(Storage::disk('private')->allFiles())->toBe([]);
    });

    it('shows in Bangla with no missing text', function () {
        $this->staffUser->forceFill(['locale' => 'bn'])->save();
        ($this->upload)('pdf', 'v1.pdf');

        foreach (['/admin/digital/assets?q=BOOK', '/admin/digital/assets/BOOK-1'] as $page) {
            $html = ($this->as)($this->staffUser)->get($page)->assertOk()->getContent();
            expect($html)->not->toContain('digital-products::');
        }
        ($this->as)($this->staffUser)->get('/admin/digital/assets/BOOK-1')->assertSee('ডিজিটাল')->assertSee('সংস্করণ');
    });
});
