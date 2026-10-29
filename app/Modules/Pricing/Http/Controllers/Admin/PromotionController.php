<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Http\Controllers\Admin;

use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Pricing\Actions\SavePromotion;
use App\Modules\Pricing\Exceptions\PromotionException;
use App\Modules\Pricing\Http\Requests\PromotionRequest;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class PromotionController
{
    public function index(): View
    {
        $default = (string) config('app.default_locale');
        $promotions = DB::table('promotions as p')
            ->leftJoin('promotion_translations as t', fn ($j) => $j->on('t.promotion_id', '=', 'p.id')->where('t.locale', app()->getLocale()))
            ->leftJoin('promotion_translations as d', fn ($j) => $j->on('d.promotion_id', '=', 'p.id')->where('d.locale', $default))
            ->orderByDesc('p.id')->get(['p.*', DB::raw('COALESCE(t.label, d.label) as label')]);

        return view('pricing::admin.promotions.index', ['promotions' => $promotions, 'now' => now('UTC')->format('Y-m-d H:i:s.u')]);
    }

    public function create(): View
    {
        return view('pricing::admin.promotions.form', $this->formData(null));
    }

    public function store(PromotionRequest $request, SavePromotion $save): RedirectResponse
    {
        return $this->persist($request, $save, null);
    }

    public function edit(string $promotion): View
    {
        return view('pricing::admin.promotions.form', $this->formData($this->find($promotion)));
    }

    public function update(PromotionRequest $request, string $promotion, SavePromotion $save): RedirectResponse
    {
        return $this->persist($request, $save, $this->find($promotion));
    }

    private function persist(PromotionRequest $request, SavePromotion $save, ?\stdClass $existing): RedirectResponse
    {
        $user = $request->user('admin');
        abort_if($user === null, 403);

        $type = (string) $request->validated('type') === 'fixed' ? 'fixed' : 'percent';
        $value = $type === 'percent' ? $request->basisPoints((string) $request->input('value')) : $request->taka((string) $request->input('value'));

        try {
            $save->handle(
                new Actor(ActorType::Staff, (int) $user->getAuthIdentifier(), $user->public_id, $user->name), $existing === null ? null : (int) $existing->id, $type, (int) $value,
                $request->filled('max_discount') ? $request->taka((string) $request->input('max_discount')) : null, $request->filled('min_spend') ? (int) $request->taka((string) $request->input('min_spend')) : 0,
                $request->dhakaTime($request->validated('starts_at')), $request->dhakaTime($request->validated('ends_at')), $request->boolean('is_active'), $request->labels(),
            );
        } catch (PromotionException $e) {
            return back()->withInput()->withErrors(['promotion' => __('pricing::promotions.errors.'.$e->reason, $e->params)]);
        }

        return redirect()->route('admin.promotions.promotions.index')->with('status', __('pricing::promotions.saved'));
    }

    private function find(string $publicId): \stdClass
    {
        return DB::table('promotions')->where('public_id', $publicId)->first() ?? abort(404);
    }

    /** @return array<string, mixed> */
    private function formData(?\stdClass $p): array
    {
        $zone = fn (?string $utc): string => $utc === null ? '' : CarbonImmutable::parse($utc, 'UTC')->setTimezone('Asia/Dhaka')->format('Y-m-d\TH:i');

        return [
            'promotion' => $p,
            'translations' => $p === null ? collect() : DB::table('promotion_translations')->where('promotion_id', $p->id)->get(),
            'valueText' => $p === null ? '' : ($p->type === 'percent' ? rtrim(rtrim(number_format(((int) $p->value) / 100, 2, '.', ''), '0'), '.') : Money::of((int) $p->value)->toMajorString()),
            'maxText' => $p?->max_discount_minor === null ? '' : Money::of((int) $p->max_discount_minor)->toMajorString(),
            'minText' => $p === null || (int) $p->min_spend_minor === 0 ? '' : Money::of((int) $p->min_spend_minor)->toMajorString(),
            'startsText' => $zone($p?->starts_at), 'endsText' => $zone($p?->ends_at),
        ];
    }
}
