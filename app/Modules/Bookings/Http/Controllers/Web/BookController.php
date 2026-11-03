<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Http\Controllers\Web;

use App\Modules\Bookings\Actions\HoldSlot;
use App\Modules\Bookings\Exceptions\BookingException;
use App\Modules\Bookings\Models\ServiceDefinition;
use App\Modules\Bookings\Models\ServicePackage;
use App\Modules\Bookings\Services\SlotSearch;
use App\Modules\Cart\Contracts\CartOwnerResolver;
use App\Modules\Catalog\Contracts\VariantDirectory;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Pricing\Contracts\PriceCatalog;
use DateTimeImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Where a customer chooses a time for a service: pick a day, see the times that are really free, press one. Pressing it holds that time for a few minutes and
 * puts the booking in the cart. Anyone may look; nothing is held until they press a time.
 */
class BookController
{
    public function __construct(private readonly VariantDirectory $variants, private readonly SlotSearch $search, private readonly PriceCatalog $prices, private readonly Clock $clock) {}

    public function show(Request $request, string $sku): View
    {
        $variant = $this->variants->findBySku($sku);
        $package = $variant === null || $variant->productType !== 'service' ? null : ServicePackage::query()->where('variant_id', $variant->id)->where('is_active', true)->first();
        $service = $package === null ? null : ServiceDefinition::query()->where('id', $package->service_id)->where('is_active', true)->first();
        abort_if($variant === null || $package === null || $service === null, 404);

        $zone = (string) config('bookings.timezone');
        $today = $this->clock->now()->setTimezone($zone);
        $last = $today->addDays($service->max_horizon_days)->format('Y-m-d');
        $date = (string) $request->query('date', $today->addDay()->format('Y-m-d'));
        $slots = [];
        $problem = null;
        $valid = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($valid === false || $valid->format('Y-m-d') !== $date) {
            $date = $today->addDay()->format('Y-m-d');
            $problem = 'date_invalid';
        } elseif ($date <= $last) {
            $slots = $this->search->forPackage($package, $date, $date);
        }

        try {
            $price = $this->prices->quote($variant->id, $variant->taxClassCode)->price;
        } catch (Throwable) {
            $price = null;
        }

        return view('bookings::book.show', [
            'variant' => $variant, 'package' => $package, 'service' => $service, 'price' => $price instanceof Money ? $price : null, 'date' => $date, 'min' => $today->format('Y-m-d'), 'max' => $last,
            'slots' => array_slice($slots, 0, 60), 'problem' => $problem, 'zone' => $zone,
        ]);
    }

    public function hold(Request $request, HoldSlot $hold, CartOwnerResolver $owners, string $sku): RedirectResponse
    {
        $data = $request->validate(['resource' => ['required', 'string', 'size:26', 'alpha_num'], 'start' => ['required', 'string', 'max:40']]);

        try {
            $hold->handle($owners->forWrite($request), $sku, $data['resource'], $data['start']);
        } catch (BookingException $e) {
            return back()->withErrors(['slot' => __('bookings::errors.'.$e->reason, $e->params)]);
        }

        return redirect()->route('cart.show', ['locale' => app()->getLocale()])->with('status', __('bookings::book.held'));
    }
}
