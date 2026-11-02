<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Http\Controllers\Admin;

use App\Modules\Catalog\Contracts\VariantDirectory;
use App\Modules\DigitalProducts\Actions\ImportLicenseKeys;
use App\Modules\DigitalProducts\Actions\UploadDigitalAsset;
use App\Modules\DigitalProducts\Exceptions\DigitalException;
use App\Modules\DigitalProducts\Http\Concerns\ActsAsStaff;
use App\Modules\DigitalProducts\Http\Requests\ImportKeysRequest;
use App\Modules\DigitalProducts\Http\Requests\UploadAssetRequest;
use App\Modules\DigitalProducts\Models\DigitalAsset;
use App\Modules\DigitalProducts\Services\KeyPoolReader;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** The files behind digital products, for staff. A file can be put in and its history seen; no screen hands out the file itself. */
class AssetController
{
    use ActsAsStaff;

    public function index(Request $request, VariantDirectory $variants): View
    {
        $term = trim((string) $request->query('q', ''));
        $found = $term === '' ? [] : array_values(array_filter($variants->search($term, 25), fn ($v) => $v->productType === 'digital'));
        $assets = DigitalAsset::query()->whereIn('variant_id', array_map(fn ($v) => $v->id, $found))->withCount('versions')->get()->keyBy('variant_id');

        return view('digital-products::admin.index', ['term' => $term, 'variants' => $found, 'assets' => $assets]);
    }

    public function show(Request $request, VariantDirectory $variants, KeyPoolReader $pools, string $sku): View
    {
        $variant = $variants->findBySku($sku);
        abort_if($variant === null || $variant->productType !== 'digital', 404);
        $asset = DigitalAsset::query()->where('variant_id', $variant->id)->first();

        return view('digital-products::admin.show', [
            'variant' => $variant, 'keys' => $pools->forVariant($variant->id), 'asset' => $asset, 'versions' => $asset?->versions()->get() ?? collect(),
            'can' => $request->user('admin')?->can('digital.manage') === true, 'max' => (int) config('digital.max_upload_mb'), 'types' => implode(', ', array_keys((array) config('digital.allowed'))),
        ]);
    }

    public function upload(UploadAssetRequest $request, UploadDigitalAsset $upload, string $sku): RedirectResponse
    {
        $file = $request->file('file');
        abort_if($file === null, 422);

        try {
            $version = $upload->handle($this->actor($request), $sku, (string) $file->getRealPath(), $file->getClientOriginalName());
        } catch (DigitalException $e) {
            return back()->withErrors(['asset' => __('digital-products::errors.'.$e->reason, $e->params)]);
        }

        return back()->with('status', __('digital-products::admin.uploaded', ['version' => $version->version]));
    }

    public function importKeys(ImportKeysRequest $request, ImportLicenseKeys $import, string $sku): RedirectResponse
    {
        $file = $request->file('file');
        abort_if($file === null, 422);

        try {
            $result = $import->handle($this->actor($request), $sku, (string) file_get_contents((string) $file->getRealPath()));
        } catch (DigitalException $e) {
            return back()->withErrors(['asset' => __('digital-products::errors.'.$e->reason, $e->params)]);
        }

        return back()->with('status', __('digital-products::admin.keys.imported', ['added' => $result->added, 'duplicates' => $result->duplicates, 'invalid' => $result->invalid, 'filled' => $result->filled]));
    }
}
