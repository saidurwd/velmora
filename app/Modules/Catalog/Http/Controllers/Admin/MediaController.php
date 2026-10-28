<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Admin;

use App\Modules\Catalog\Actions\AddProductMedia;
use App\Modules\Catalog\Actions\ManageProductMedia;
use App\Modules\Catalog\Exceptions\MediaRejected;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductMedia;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class MediaController
{
    public function store(Request $request, Product $product, AddProductMedia $add): RedirectResponse
    {
        $default = (string) config('app.default_locale');
        $data = $request->validate([
            'image' => ['required', 'file', 'max:'.(int) ceil((int) config('catalog.media.max_upload_bytes') / 1024)],
            'decorative' => ['sometimes', 'boolean'],
            'alts' => ['nullable', 'array'],
            'alts.*' => ['nullable', 'string', 'max:200'],
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('image');
        $alts = array_map('strval', (array) ($data['alts'] ?? []));

        try {
            $add->handle($this->actor($request), $product, (string) file_get_contents($file->getRealPath()), $file->getClientOriginalName(), $alts, null, $request->boolean('decorative'));
        } catch (MediaRejected $e) {
            return $this->back($product)->withErrors([$e->reason === 'alt_required' ? "alts.{$default}" : 'image' => $e->getMessage()]);
        }

        return $this->back($product)->with('status', __('catalog::admin.saved'));
    }

    public function primary(Request $request, Product $product, ProductMedia $media, ManageProductMedia $manage): RedirectResponse
    {
        $this->owned($product, $media);
        $manage->makePrimary($this->actor($request), $media);

        return $this->back($product)->with('status', __('catalog::admin.saved'));
    }

    public function move(Request $request, Product $product, ProductMedia $media, ManageProductMedia $manage): RedirectResponse
    {
        $this->owned($product, $media);
        $direction = $request->validate(['direction' => ['required', 'in:up,down']])['direction'];

        $order = $product->media()->pluck('public_id')->map(fn ($v) => (string) $v)->values()->all();
        $i = array_search($media->public_id, $order, true);
        $j = $direction === 'up' ? $i - 1 : $i + 1;

        if ($i !== false && isset($order[$j])) {
            [$order[$i], $order[$j]] = [$order[$j], $order[$i]];
            $manage->reorder($this->actor($request), $product->id, array_values($order));
        }

        return $this->back($product);
    }

    public function destroy(Request $request, Product $product, ProductMedia $media, ManageProductMedia $manage): RedirectResponse
    {
        $this->owned($product, $media);
        $manage->delete($this->actor($request), $media);

        return $this->back($product)->with('status', __('catalog::admin.saved'));
    }

    private function back(Product $product): RedirectResponse
    {
        return redirect()->route('admin.catalog.products.edit', $product);
    }

    private function owned(Product $product, ProductMedia $media): void
    {
        abort_unless($media->product_id === $product->id, 404);
    }

    private function actor(Request $request): Actor
    {
        $user = $request->user('admin');
        abort_if($user === null, 403);

        return new Actor(ActorType::Staff, (int) $user->getAuthIdentifier(), $user->public_id, $user->name);
    }
}
