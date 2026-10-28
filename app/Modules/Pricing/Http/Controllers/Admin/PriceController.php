<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Http\Controllers\Admin;

use App\Modules\Catalog\Contracts\VariantDirectory;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Exceptions\MoneyException;
use App\Modules\Pricing\Actions\ScheduleSale;
use App\Modules\Pricing\Actions\SetRegularPrice;
use App\Modules\Pricing\Exceptions\PricingException;
use App\Modules\Pricing\Services\PriceAdminReader;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PriceController
{
    public function index(Request $request, VariantDirectory $variants, PriceAdminReader $reader): View
    {
        $term = trim((string) $request->query('q', ''));
        $found = $term === '' ? [] : $variants->search($term, 25);
        $prices = $reader->regularPrices(array_map(fn ($v) => $v->id, $found));

        return view('pricing::admin.index', ['term' => $term, 'variants' => $found, 'prices' => $prices]);
    }

    public function show(string $sku, VariantDirectory $variants, PriceAdminReader $reader): View
    {
        $variant = $variants->findBySku($sku) ?? abort(404);

        return view('pricing::admin.show', [
            'variant' => $variant,
            'price' => $reader->regularPrices([$variant->id])[$variant->id] ?? null,
            'sales' => $reader->upcomingSales($variant->id),
        ]);
    }

    public function update(Request $request, string $sku, VariantDirectory $variants, SetRegularPrice $set): RedirectResponse
    {
        $variant = $variants->findBySku($sku) ?? abort(404);
        $data = $request->validate(['amount' => ['required', 'string', 'max:20'], 'compare_at' => ['nullable', 'string', 'max:20']]);

        return $this->run($sku, function () use ($request, $set, $variant, $data): void {
            $compare = ($data['compare_at'] ?? '') === '' ? null : Money::fromMajorString($data['compare_at']);
            $set->handle($this->actor($request), $variant->id, Money::fromMajorString($data['amount']), $compare);
        });
    }

    public function schedule(Request $request, string $sku, VariantDirectory $variants, ScheduleSale $schedule): RedirectResponse
    {
        $variant = $variants->findBySku($sku) ?? abort(404);
        $data = $request->validate(['sale_amount' => ['required', 'string', 'max:20'], 'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date']]);

        return $this->run($sku, fn () => $schedule->handle($this->actor($request), $variant->id, Money::fromMajorString($data['sale_amount']), $data['starts_at'], $data['ends_at']));
    }

    public function cancelSale(Request $request, string $sku, int $sale, VariantDirectory $variants, PriceAdminReader $reader, ScheduleSale $schedule): RedirectResponse
    {
        $variant = $variants->findBySku($sku) ?? abort(404);
        abort_unless($reader->saleBelongsToVariant($sale, $variant->id), 404);

        return $this->run($sku, fn () => $schedule->cancel($this->actor($request), $sale));
    }

    private function run(string $sku, callable $work): RedirectResponse
    {
        try {
            $work();
        } catch (PricingException|MoneyException $e) {
            return redirect()->route('admin.pricing.show', $sku)->withInput()->withErrors(['price' => $e->getMessage()]);
        }

        return redirect()->route('admin.pricing.show', $sku)->with('status', __('pricing::admin.saved'));
    }

    private function actor(Request $request): Actor
    {
        $user = $request->user('admin');
        abort_if($user === null, 403);

        return new Actor(ActorType::Staff, (int) $user->getAuthIdentifier(), $user->public_id, $user->name);
    }
}
