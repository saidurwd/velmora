<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Http\Controllers\Admin;

use App\Modules\Catalog\Contracts\VariantDirectory;
use App\Modules\Catalog\DTOs\VariantSummary;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Pricing\Actions\BulkChangePrices;
use App\Modules\Pricing\DTOs\BulkPricePreview;
use App\Modules\Pricing\Exceptions\PricingException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Preview first, apply second; the apply step recomputes and refuses if the previewed prices moved. */
class BulkPriceController
{
    public function form(): View
    {
        return view('pricing::admin.bulk', ['preview' => null, 'skus' => [], 'summaries' => []]);
    }

    public function preview(Request $request, VariantDirectory $variants, BulkChangePrices $bulk): View|RedirectResponse
    {
        [$skus, $percentBp, $round] = $this->input($request);
        $found = $this->resolve($variants, $skus);

        if ($found === []) {
            return back()->withInput()->withErrors(['skus' => __('pricing::admin.bulk.no_match')]);
        }

        $preview = $bulk->preview(array_keys($found), $percentBp, $round);

        return view('pricing::admin.bulk', ['preview' => $preview, 'skus' => $skus, 'summaries' => $found, 'percent' => $request->input('percent'), 'round' => $round]);
    }

    public function apply(Request $request, VariantDirectory $variants, BulkChangePrices $bulk): RedirectResponse
    {
        [$skus, $percentBp, $round] = $this->input($request);
        $fingerprint = (string) $request->validate(['fingerprint' => ['required', 'string', 'size:64']])['fingerprint'];
        $preview = $bulk->preview(array_keys($this->resolve($variants, $skus)), $percentBp, $round);

        try {
            if (! hash_equals($fingerprint, $preview->fingerprint)) {
                throw PricingException::stalePreview();
            }
            $count = $bulk->apply($this->actor($request), new BulkPricePreview($preview->rows, $preview->listCode, $preview->fingerprint));
        } catch (PricingException $e) {
            return redirect()->route('admin.pricing.bulk')->withErrors(['skus' => $e->getMessage()]);
        }

        return redirect()->route('admin.pricing.bulk')->with('status', __('pricing::admin.bulk.applied', ['count' => $count]));
    }

    /** @return array{0: list<string>, 1: int, 2: int} */
    private function input(Request $request): array
    {
        $data = $request->validate([
            'skus' => ['required', 'string', 'max:20000'],
            'percent' => ['required', 'string', 'max:10'],
            'round' => ['nullable', 'in:1,100,500'],
        ]);

        $percent = BanglaText::numeric($data['percent']);
        if (! preg_match('/^-?\d{1,3}(\.\d{1,2})?$/', $percent) || abs((float) $percent) > 500) {
            throw ValidationException::withMessages(['percent' => __('pricing::admin.bulk.percent_invalid')]);
        }

        $skus = array_values(array_unique(array_filter(array_map(fn ($s) => strtoupper(trim($s)), preg_split('/[\s,]+/', $data['skus']) ?: []))));

        // percent → basis points without floats: "-12.5" → -1250
        [$whole, $frac] = array_pad(explode('.', ltrim($percent, '-')), 2, '0');
        $bp = ((int) $whole * 100 + (int) str_pad($frac, 2, '0')) * (str_starts_with($percent, '-') ? -1 : 1);

        return [$skus, $bp, (int) ($data['round'] ?? 1)];
    }

    /**
     * @param  list<string>  $skus
     * @return array<int, VariantSummary> keyed by variant id
     */
    private function resolve(VariantDirectory $variants, array $skus): array
    {
        $out = [];
        foreach (array_slice($skus, 0, 500) as $sku) {
            if (($v = $variants->findBySku($sku)) !== null) {
                $out[$v->id] = $v;
            }
        }

        return $out;
    }

    private function actor(Request $request): Actor
    {
        $user = $request->user('admin');
        abort_if($user === null, 403);

        return new Actor(ActorType::Staff, (int) $user->getAuthIdentifier(), $user->public_id, $user->name);
    }
}
