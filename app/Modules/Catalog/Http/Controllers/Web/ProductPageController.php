<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Web;

use App\Modules\Catalog\Services\Storefront\ProductPageBuilder;
use App\Modules\Catalog\Services\Storefront\RelatedProducts;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProductPageController
{
    public function show(Request $request, string $slug, ProductPageBuilder $builder, RelatedProducts $related): View|RedirectResponse
    {
        $locale = app()->getLocale();
        $found = $builder->find($slug, $locale) ?? abort(404);

        // a slug from another language (or an old spelling) leads to this language's address
        if ($found['canonicalSlug'] !== $slug) {
            return redirect()->to(url("/{$locale}/p/".rawurlencode($found['canonicalSlug'])).($request->getQueryString() ? '?'.$request->getQueryString() : ''), 301);
        }

        $requested = [];
        foreach (is_array($request->query('opt')) ? array_slice($request->query('opt'), 0, 10, true) : [] as $code => $value) {
            if (is_string($code) && is_string($value) && strlen($code) <= 60 && strlen($value) <= 60) {
                $requested[strtolower($code)] = strtolower($value);
            }
        }

        $page = $builder->build($found['product'], $requested, $locale) ?? abort(404);

        return view('catalog::storefront.product', [
            'page' => $page,
            'related' => $related->for($found['product']->id, $locale, 4),
            'productPath' => url("/{$locale}/p/".rawurlencode($page->slug)),
        ]);
    }
}
