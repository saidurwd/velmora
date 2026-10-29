<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Controllers\Admin;

use App\Modules\Customers\Contracts\Geography;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Shipping\Actions\SaveShippingZone;
use App\Modules\Shipping\Exceptions\ShippingException;
use App\Modules\Shipping\Http\Requests\ZoneRequest;
use App\Modules\Shipping\Models\ShippingMethod;
use App\Modules\Shipping\Models\ShippingZone;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class ZoneController
{
    public function __construct(private readonly Geography $geography) {}

    public function index(): View
    {
        $zones = ShippingZone::query()->with('translations')->orderBy('sort_order')->orderBy('id')->get();

        return view('shipping::admin.zones.index', [
            'zones' => $zones,
            'districtCounts' => DB::table('shipping_zone_districts')->select('zone_id', DB::raw('COUNT(*) as n'))->groupBy('zone_id')->pluck('n', 'zone_id'),
            'hasFallback' => $zones->contains(fn (ShippingZone $z) => $z->is_fallback && $z->is_active),
        ]);
    }

    public function create(): View
    {
        return view('shipping::admin.zones.form', $this->formData(null));
    }

    public function store(ZoneRequest $request, SaveShippingZone $save): RedirectResponse
    {
        return $this->persist($request, $save, null);
    }

    public function edit(ShippingZone $zone): View
    {
        return view('shipping::admin.zones.form', $this->formData($zone->load('translations')));
    }

    public function update(ZoneRequest $request, ShippingZone $zone, SaveShippingZone $save): RedirectResponse
    {
        return $this->persist($request, $save, $zone);
    }

    private function persist(ZoneRequest $request, SaveShippingZone $save, ?ShippingZone $zone): RedirectResponse
    {
        $user = $request->user('admin');
        abort_if($user === null, 403);

        $valid = collect($this->geography->districtsByDivision())->flatten(1)->pluck('id')->map(fn ($i) => (int) $i)->all();
        $districts = $request->districtIds();
        if (array_diff($districts, $valid) !== []) {
            return back()->withInput()->withErrors(['districts' => __('shipping::admin.errors.district_unknown')]);
        }

        try {
            $save->handle(
                new Actor(ActorType::Staff, (int) $user->getAuthIdentifier(), $user->public_id, $user->name), $zone, $request->names(),
                $request->boolean('is_fallback'), $request->boolean('cod_available'), $request->boolean('is_active'), (int) $request->validated('sort_order', 0), $districts,
                $request->rates(ShippingMethod::query()->pluck('id', 'public_id')->map(fn ($i) => (int) $i)->all()),
            );
        } catch (ShippingException $e) {
            return back()->withInput()->withErrors(['rates' => __('shipping::admin.errors.'.$e->reason, $e->params)]);
        }

        return redirect()->route('admin.shipping.zones.index')->with('status', __('shipping::admin.saved'));
    }

    /** @return array<string, mixed> */
    private function formData(?ShippingZone $zone): array
    {
        $owners = DB::table('shipping_zone_districts as zd')->join('shipping_zones as z', 'z.id', '=', 'zd.zone_id')
            ->leftJoin('shipping_zone_translations as t', fn ($j) => $j->on('t.zone_id', '=', 'z.id')->where('t.locale', app()->getLocale()))
            ->leftJoin('shipping_zone_translations as te', fn ($j) => $j->on('te.zone_id', '=', 'z.id')->where('te.locale', (string) config('app.default_locale')))
            ->get(['zd.district_id', 'z.id as zone_id', DB::raw('COALESCE(t.name, te.name) as name')]);

        $bands = $zone === null ? collect() : DB::table('shipping_rates')->where('zone_id', $zone->id)->orderByRaw('max_weight_g IS NULL')->orderBy('max_weight_g')->get()->groupBy('method_id');

        return [
            'zone' => $zone,
            'divisions' => $this->geography->divisions(),
            'districts' => $this->geography->districtsByDivision(),
            'owners' => $owners->keyBy('district_id'),
            'methods' => ShippingMethod::query()->with('translations')->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get(),
            'bands' => $bands,
        ];
    }
}
