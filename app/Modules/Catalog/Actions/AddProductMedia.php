<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Actions;

use App\Modules\Catalog\Contracts\MalwareScanner;
use App\Modules\Catalog\Events\ProductListingChanged;
use App\Modules\Catalog\Exceptions\MediaRejected;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductMedia;
use App\Modules\Catalog\Services\Media\ImageProcessor;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class AddProductMedia
{
    public function __construct(
        private readonly ImageProcessor $images,
        private readonly MalwareScanner $scanner,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, string>  $alts  locale => alt text (default language required unless decorative)
     *
     * @throws MediaRejected
     */
    public function handle(Actor $actor, Product $product, string $bytes, string $originalName, array $alts, ?int $variantId = null, bool $decorative = false): ProductMedia
    {
        $default = (string) config('app.default_locale');

        if (! $decorative && trim($alts[$default] ?? '') === '') {
            throw MediaRejected::altRequired($default);
        }

        if (! $this->scanner->isClean($bytes, $originalName)) {
            throw MediaRejected::malware();
        }

        $processed = $this->images->process($bytes); // validates type, size and dimensions; strips everything but pixels

        $disk = (string) config('catalog.media.disk');
        $base = "products/{$product->public_id}/".strtolower((string) Str::ulid());
        $stored = [];

        try {
            Storage::disk($disk)->put("{$base}/master.webp", $processed['master'], 'public');
            $stored[] = "{$base}/master.webp";
            $paths = [];

            foreach ($processed['renditions'] as $format => $bySize) {
                foreach ($bySize as $width => $data) {
                    $path = "{$base}/w{$width}.{$format}";
                    Storage::disk($disk)->put($path, $data, 'public');
                    $stored[] = $path;
                    $paths[$format][$width] = $path;
                }
            }

            return Transactions::run(function () use ($actor, $product, $variantId, $decorative, $alts, $processed, $disk, $base, $paths): ProductMedia {
                $first = ! ProductMedia::query()->where('product_id', $product->id)->exists();

                $media = new ProductMedia;
                $media->forceFill([
                    'product_id' => $product->id, 'variant_id' => $variantId, 'disk' => $disk, 'base_path' => $base,
                    'width' => $processed['width'], 'height' => $processed['height'], 'renditions' => $paths, 'size_bytes' => strlen($processed['master']),
                    'is_primary' => $first, 'is_decorative' => $decorative,
                    'sort_order' => (int) ProductMedia::query()->where('product_id', $product->id)->max('sort_order') + 1,
                ])->save();

                foreach ($alts as $locale => $alt) {
                    if (trim($alt) !== '') {
                        DB::table('product_media_translations')->insert(['media_id' => $media->id, 'locale' => $locale, 'alt' => BanglaText::input($alt)]);
                    }
                }

                $this->audit->record('media.added', $media, after: ['product_id' => $product->id, 'width' => $processed['width'], 'height' => $processed['height'], 'renditions' => array_map('count', $paths)], actor: $actor);

                ProductListingChanged::dispatch($product->id);

                return $media->load('translations');
            });
        } catch (\Throwable $e) {
            Storage::disk($disk)->delete($stored); // never leave orphaned files behind when the database step fails

            throw $e;
        }
    }
}
