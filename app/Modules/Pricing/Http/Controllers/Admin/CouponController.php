<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Http\Controllers\Admin;

use App\Modules\Catalog\Contracts\ScopeDirectory;
use App\Modules\Orders\Contracts\OrderDirectory;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\Money;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Pricing\Actions\SaveCoupon;
use App\Modules\Pricing\DTOs\CouponData;
use App\Modules\Pricing\Exceptions\PromotionException;
use App\Modules\Pricing\Http\Requests\CouponRequest;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CouponController
{
    public const PER_PAGE = 25;

    public function __construct(private readonly ScopeDirectory $scope) {}

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $usage = DB::table('coupon_redemptions')->select('coupon_id', DB::raw('COUNT(*) as uses'), DB::raw('SUM(amount_minor) as given'))->groupBy('coupon_id');

        $coupons = DB::table('coupons as c')->leftJoinSub($usage, 'r', 'r.coupon_id', '=', 'c.id')
            ->when($q !== '', fn ($w) => $w->where('c.code', 'like', '%'.addcslashes(strtoupper($q), '%_\\').'%'))
            ->orderByDesc('c.id')->select('c.*', DB::raw('COALESCE(r.given, 0) as given'))->paginate(self::PER_PAGE)->withQueryString();

        return view('pricing::admin.coupons.index', ['coupons' => $coupons, 'q' => $q, 'now' => now('UTC')->format('Y-m-d H:i:s.u')]);
    }

    public function create(): View
    {
        return view('pricing::admin.coupons.form', $this->formData(null));
    }

    public function store(CouponRequest $request, SaveCoupon $save): RedirectResponse
    {
        return $this->persist($request, $save, null);
    }

    public function edit(string $coupon): View
    {
        return view('pricing::admin.coupons.form', $this->formData($this->find($coupon)));
    }

    public function update(CouponRequest $request, string $coupon, SaveCoupon $save): RedirectResponse
    {
        return $this->persist($request, $save, $this->find($coupon));
    }

    /** Usage report: how much it has been used, how much it has cost, and the orders that used it. */
    public function show(string $coupon, OrderDirectory $orders): View
    {
        $row = $this->find($coupon);
        $stats = DB::table('coupon_redemptions')->where('coupon_id', $row->id)->selectRaw('COUNT(*) as uses, COALESCE(SUM(amount_minor), 0) as given, COUNT(DISTINCT redeemer) as people')->first();
        $page = DB::table('coupon_redemptions')->where('coupon_id', $row->id)->orderByDesc('id')->paginate(50);

        return view('pricing::admin.coupons.show', [
            'coupon' => $row, 'uses' => (int) ($stats->uses ?? 0), 'given' => (int) ($stats->given ?? 0), 'people' => (int) ($stats->people ?? 0),
            'redemptions' => $page, 'orders' => $orders->references(array_values(array_map(fn ($r) => (int) $r->order_id, $page->items()))),
        ]);
    }

    private function persist(CouponRequest $request, SaveCoupon $save, ?\stdClass $existing): RedirectResponse
    {
        $user = $request->user('admin');
        abort_if($user === null, 403);

        $type = match ((string) $request->validated('type')) {
            'percent' => 'percent',
            'fixed' => 'fixed',
            default => 'free_shipping',
        };
        $value = match ($type) {
            'percent' => $request->basisPoints((string) $request->input('value', '')) ?? 0,
            'fixed' => $request->taka((string) $request->input('value', '')) ?? 0,
            default => 0,
        };

        $scope = match ((string) $request->validated('scope')) {
            'products' => 'products',
            'categories' => 'categories',
            default => 'all',
        };
        $productIds = [];
        if ($scope === 'products') {
            $skus = $request->skuList();
            $found = $this->scope->productIdsBySku($skus);
            $missing = array_values(array_filter($skus, fn (string $s) => ! isset($found[mb_strtoupper($s)])));
            if ($missing !== []) {
                return back()->withInput()->withErrors(['skus' => __('pricing::promotions.errors.sku_unknown', ['skus' => implode(', ', array_slice($missing, 0, 5))])]);
            }
            $productIds = array_values($found);
        }

        $categoryIds = [];
        if ($scope === 'categories') {
            $valid = array_column($this->scope->categoryOptions(app()->getLocale()), 'id');
            $categoryIds = array_values(array_map('intval', (array) $request->validated('categories', [])));
            if (array_diff($categoryIds, $valid) !== []) {
                return back()->withInput()->withErrors(['categories' => __('pricing::promotions.errors.category_unknown')]);
            }
        }

        $data = new CouponData(
            $request->validated('description'), $type, $value, $request->filled('max_discount') ? $request->taka((string) $request->input('max_discount')) : null,
            $request->filled('min_spend') ? (int) $request->taka((string) $request->input('min_spend')) : 0, $scope, $productIds, $categoryIds,
            $request->dhakaTime($request->validated('starts_at')), $request->dhakaTime($request->validated('ends_at')),
            $request->filled('usage_limit') ? (int) $request->validated('usage_limit') : null, $request->filled('per_customer_limit') ? (int) $request->validated('per_customer_limit') : null,
            $request->boolean('is_active'),
        );

        try {
            $id = $save->handle(new Actor(ActorType::Staff, (int) $user->getAuthIdentifier(), $user->public_id, $user->name), $existing === null ? null : (int) $existing->id, $request->validated('code'), $data);
        } catch (PromotionException $e) {
            return back()->withInput()->withErrors(['coupon' => __('pricing::promotions.errors.'.$e->reason, $e->params)]);
        }

        return redirect()->route('admin.promotions.coupons.show', (string) DB::table('coupons')->where('id', $id)->value('public_id'))->with('status', __('pricing::promotions.saved'));
    }

    private function find(string $publicId): \stdClass
    {
        return DB::table('coupons')->where('public_id', $publicId)->first() ?? abort(404);
    }

    /** @return array<string, mixed> */
    private function formData(?\stdClass $coupon): array
    {
        $productIds = $coupon === null ? [] : DB::table('coupon_products')->where('coupon_id', $coupon->id)->pluck('product_id')->map(fn ($i) => (int) $i)->all();
        $zone = fn (?string $utc): string => $utc === null ? '' : CarbonImmutable::parse($utc, 'UTC')->setTimezone('Asia/Dhaka')->format('Y-m-d\TH:i');

        return [
            'coupon' => $coupon,
            'categoryOptions' => $this->scope->categoryOptions(app()->getLocale()),
            'chosenCategories' => $coupon === null ? [] : DB::table('coupon_categories')->where('coupon_id', $coupon->id)->pluck('category_id')->map(fn ($i) => (int) $i)->all(),
            'skuText' => implode("\n", $this->scope->skusForProducts(array_values($productIds))),
            'valueText' => $coupon === null ? '' : match ($coupon->type) {
                'percent' => rtrim(rtrim(number_format(((int) $coupon->value) / 100, 2, '.', ''), '0'), '.'),
                'fixed' => Money::of((int) $coupon->value)->toMajorString(),
                default => '',
            },
            'maxText' => $coupon?->max_discount_minor === null ? '' : Money::of((int) $coupon->max_discount_minor)->toMajorString(),
            'minText' => $coupon === null || (int) $coupon->min_spend_minor === 0 ? '' : Money::of((int) $coupon->min_spend_minor)->toMajorString(),
            'startsText' => $zone($coupon?->starts_at), 'endsText' => $zone($coupon?->ends_at),
        ];
    }
}
