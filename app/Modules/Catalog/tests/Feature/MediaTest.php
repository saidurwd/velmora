<?php

declare(strict_types=1);

use App\Modules\Catalog\Actions\AddProductMedia;
use App\Modules\Catalog\Actions\ManageProductMedia;
use App\Modules\Catalog\Actions\SaveCategory;
use App\Modules\Catalog\Actions\SaveProduct;
use App\Modules\Catalog\Contracts\MalwareScanner;
use App\Modules\Catalog\DTOs\ProductData;
use App\Modules\Catalog\DTOs\TranslationInput;
use App\Modules\Catalog\DTOs\VariantData;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Catalog\Exceptions\MediaRejected;
use App\Modules\Catalog\Models\ProductMedia;
use App\Modules\Catalog\Services\Media\ImageProcessor;
use App\Modules\Catalog\Services\Media\MediaUrls;
use App\Modules\Platform\Database\Seeders\LanguageSeeder;
use App\Modules\Platform\DTOs\Actor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('media');
    config(['catalog.media.disk' => 'media']);
    $this->seed(LanguageSeeder::class);
    $this->actor = Actor::system('test');
    $category = app(SaveCategory::class)->handle($this->actor, null, null, [new TranslationInput('en', 'Clothing')]);
    $this->product = app(SaveProduct::class)->handle($this->actor, null, new ProductData(ProductType::Physical, [new TranslationInput('en', 'Shirt')], categoryIds: [$category->id], defaultVariant: new VariantData('SHIRT-1', trackInventory: true)));
});

function jpeg(int $w = 1200, int $h = 900, int $quality = 85): string
{
    $im = imagecreatetruecolor($w, $h);
    for ($i = 0; $i < 40; $i++) { // some texture so the file is not trivially tiny
        imagefilledrectangle($im, mt_rand(0, $w), mt_rand(0, $h), mt_rand(0, $w), mt_rand(0, $h), imagecolorallocate($im, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
    }
    ob_start();
    imagejpeg($im, null, $quality);

    return (string) ob_get_clean();
}

function png(int $w = 800, int $h = 600): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 30, 120, 200));
    ob_start();
    imagepng($im);

    return (string) ob_get_clean();
}

/** Inserts an APP1 "Exif" segment (with a fake GPS marker) right after the JPEG SOI marker. */
function withExif(string $jpeg): string
{
    $payload = "Exif\0\0".'GPSLatitude=23.8103;GPSLongitude=90.4125;Make=SecretCam';
    $segment = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

    return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
}

function add(string $bytes, string $name = 'photo.jpg', array $alts = ['en' => 'A blue cotton shirt'], ?int $variantId = null, bool $decorative = false): ProductMedia
{
    return app(AddProductMedia::class)->handle(Actor::system('test'), test()->product, $bytes, $name, $alts, $variantId, $decorative);
}

function storedBytes(): string
{
    return collect(Storage::disk('media')->allFiles())->map(fn ($f) => Storage::disk('media')->get($f))->implode("\n");
}

describe('processing', function () {
    it('stores a re-encoded master plus responsive WebP sizes, never upscaling', function () {
        $media = add(jpeg(1800, 1200));
        $widths = array_map('intval', array_keys($media->renditions['webp']));
        sort($widths);

        expect($widths)->toBe([320, 640, 960, 1280, 1600, 1800])
            ->and($media->width)->toBe(1800)->and($media->height)->toBe(1200);

        foreach ($media->renditions['webp'] as $width => $path) {
            Storage::disk('media')->assertExists($path);
            expect(getimagesizefromstring(Storage::disk('media')->get($path))[0])->toBe((int) $width)
                ->and(getimagesizefromstring(Storage::disk('media')->get($path))['mime'])->toBe('image/webp');
        }
        Storage::disk('media')->assertExists("{$media->base_path}/master.webp");
    });

    it('does not enlarge small images and caps huge ones at the master width', function () {
        $small = add(jpeg(500, 400));
        $big = add(jpeg(3000, 2000));

        expect(array_map('intval', array_keys($small->renditions['webp'])))->toEqualCanonicalizing([320, 500])
            ->and($big->width)->toBe(2000)->and($big->height)->toBe(1333)
            ->and(max(array_map('intval', array_keys($big->renditions['webp']))))->toBe(2000);
    });

    it('accepts PNG and WebP uploads', function () {
        expect(add(png())->width)->toBe(800);

        ob_start();
        imagewebp(imagecreatetruecolor(640, 480));
        $webp = (string) ob_get_clean();
        expect(add($webp, 'x.webp')->width)->toBe(640);
    });

    it('only writes AVIF when the server can encode it', function () {
        $media = add(jpeg());

        expect(isset($media->renditions['avif']))->toBe(ImageProcessor::avifSupported());
    });

    it('does not keep the original upload', function () {
        $media = add(jpeg());

        $files = Storage::disk('media')->allFiles($media->base_path);
        expect($files)->not->toBeEmpty();
        foreach ($files as $file) {
            expect($file)->toMatch('/(master|w\d+)\.(webp|avif)$/');
        }
    });
});

describe('security', function () {
    it('strips EXIF and GPS data from the stored images', function () {
        $source = withExif(jpeg());
        expect($source)->toContain('GPSLatitude')->toContain('SecretCam'); // the test file really carries the data

        add($source);

        expect(storedBytes())->not->toContain('GPSLatitude')->not->toContain('SecretCam')->not->toContain('Exif');
    });

    it('discards code hidden inside an image (polyglot upload)', function () {
        $payload = '<?php system($_GET["c"]); ?>';
        $comment = "\xFF\xFE".pack('n', strlen($payload) + 2).$payload;
        $evil = substr(jpeg(), 0, 2).$comment.substr(jpeg(), 2).$payload; // in a COM segment AND appended after the image

        add($evil, 'shell.php.jpg');

        expect(storedBytes())->not->toContain('<?php')->not->toContain('system(');
    });

    it('judges the file by its content, not its name or claimed type', function () {
        expect(fn () => add('<?php echo 1;', 'photo.jpg'))->toThrow(MediaRejected::class, 'JPEG, PNG or WebP')
            ->and(fn () => add('<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>', 'logo.png'))->toThrow(MediaRejected::class)
            ->and(fn () => add('GIF89a'.str_repeat("\0", 400), 'a.jpg'))->toThrow(MediaRejected::class)
            ->and(fn () => add('%PDF-1.4 fake', 'a.jpg'))->toThrow(MediaRejected::class);

        expect(Storage::disk('media')->allFiles())->toBe([]);
    });

    it('rejects oversized files, tiny images and giant canvases before decoding', function () {
        config(['catalog.media.max_upload_bytes' => 5000]);
        expect(fn () => add(jpeg(1200, 900)))->toThrow(MediaRejected::class, 'larger than');

        config(['catalog.media.max_upload_bytes' => 10 * 1024 * 1024]);
        expect(fn () => add(jpeg(100, 100)))->toThrow(MediaRejected::class, 'shorter side');

        config(['catalog.media.max_pixels' => 100_000]);
        expect(fn () => add(jpeg(800, 600)))->toThrow(MediaRejected::class, 'too large');

        config(['catalog.media.max_pixels' => 40_000_000, 'catalog.media.max_dimension' => 700]);
        expect(fn () => add(jpeg(800, 400)))->toThrow(MediaRejected::class, 'too large');
    });

    it('rejects a truncated or corrupt image', function () {
        expect(fn () => add(substr(jpeg(), 0, 600)))->toThrow(MediaRejected::class);
    });

    it('rejects an upload the malware scanner flags and stores nothing', function () {
        app()->bind(MalwareScanner::class, fn () => new class implements MalwareScanner
        {
            public function isClean(string $bytes, string $originalName): bool
            {
                return false;
            }
        });

        expect(fn () => add(jpeg()))->toThrow(MediaRejected::class, 'security scan')
            ->and(Storage::disk('media')->allFiles())->toBe([])->and(ProductMedia::count())->toBe(0);
    });

    it('cleans up stored files when the database step fails', function () {
        expect(fn () => add(jpeg(), variantId: 999999))->toThrow(QueryException::class)
            ->and(Storage::disk('media')->allFiles())->toBe([])->and(ProductMedia::count())->toBe(0);
    });
});

describe('alt text', function () {
    it('requires default-language alt text unless the image is decorative', function () {
        expect(fn () => add(jpeg(), alts: []))->toThrow(MediaRejected::class, 'Alt text')
            ->and(fn () => add(jpeg(), alts: ['en' => '  ', 'bn' => 'ছবি']))->toThrow(MediaRejected::class);

        $decorative = add(jpeg(), alts: [], decorative: true);
        expect($decorative->is_decorative)->toBeTrue()->and($decorative->alt('en'))->toBe('');
    });

    it('stores alt text per language and falls back to English', function () {
        $media = add(jpeg(), alts: ['en' => 'Blue shirt', 'bn' => 'নীল শার্ট']);
        $english = add(jpeg(), alts: ['en' => 'Red shirt']);

        expect($media->alt('bn'))->toBe('নীল শার্ট')->and($media->alt('en'))->toBe('Blue shirt')
            ->and($english->alt('bn'))->toBe('Red shirt');
    });
});

describe('gallery', function () {
    it('makes the first image primary and orders the rest', function () {
        $a = add(jpeg());
        $b = add(jpeg());
        $c = add(jpeg());

        expect([$a->is_primary, $b->is_primary, $c->is_primary])->toBe([true, false, false])->and([$a->sort_order, $b->sort_order, $c->sort_order])->toBe([1, 2, 3]);

        app(ManageProductMedia::class)->reorder($this->actor, $this->product->id, [$c->public_id, $a->public_id, $b->public_id]);

        expect(ProductMedia::query()->orderBy('sort_order')->pluck('public_id')->all())->toBe([$c->public_id, $a->public_id, $b->public_id]);
    });

    it('keeps exactly one primary image, in the action and in the database', function () {
        $a = add(jpeg());
        $b = add(jpeg());

        app(ManageProductMedia::class)->makePrimary($this->actor, $b);

        expect(ProductMedia::where('is_primary', true)->pluck('id')->all())->toBe([$b->id])
            ->and(fn () => DB::table('product_media')->where('id', $a->id)->update(['is_primary' => 1]))->toThrow(QueryException::class);
    });

    it('promotes the next image when the primary is deleted, and removes the files', function () {
        $a = add(jpeg());
        $b = add(jpeg());
        $paths = collect($a->renditions['webp'])->values()->all();

        app(ManageProductMedia::class)->delete($this->actor, $a);

        expect($b->fresh()->is_primary)->toBeTrue()->and(ProductMedia::count())->toBe(1);
        foreach ($paths as $path) {
            Storage::disk('media')->assertMissing($path);
        }
        Storage::disk('media')->assertExists("{$b->base_path}/master.webp");
    });

    it('can attach an image to one variant', function () {
        $variantId = (int) $this->product->variants()->value('id');

        expect(add(jpeg(), variantId: $variantId)->variant_id)->toBe($variantId);
    });

    it('audits every change', function () {
        $a = add(jpeg());
        $b = add(jpeg());
        app(ManageProductMedia::class)->makePrimary($this->actor, $b);
        app(ManageProductMedia::class)->delete($this->actor, $a);

        expect(DB::table('audit_events')->whereIn('action', ['media.added', 'media.primary_changed', 'media.deleted'])->count())->toBe(4);
    });
});

describe('urls', function () {
    it('builds srcsets and picks the right size', function () {
        $media = add(jpeg(1800, 1200));
        $urls = app(MediaUrls::class);

        $srcset = $urls->srcsets($media)['webp'];
        expect($srcset)->toContain(' 320w')->toContain(' 1800w')->and(strpos($srcset, '320w'))->toBeLessThan(strpos($srcset, '1800w'))
            ->and($urls->url($media, 700))->toContain('w640.webp')
            ->and($urls->url($media, 100))->toContain('w320.webp')   // smaller than every size: the smallest
            ->and($urls->url($media, 5000))->toContain('w1800.webp');
    });
});
