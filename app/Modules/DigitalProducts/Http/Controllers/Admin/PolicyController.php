<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Http\Controllers\Admin;

use App\Modules\DigitalProducts\Actions\SaveRefundPolicy;
use App\Modules\DigitalProducts\Exceptions\DigitalException;
use App\Modules\DigitalProducts\Http\Concerns\ActsAsStaff;
use App\Modules\DigitalProducts\Http\Requests\SavePolicyRequest;
use App\Modules\DigitalProducts\Models\RefundPolicy;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/** The refund policies customers read before they buy a digital product, edited by staff. */
class PolicyController
{
    use ActsAsStaff;

    public function index(): View
    {
        return view('digital-products::admin.policies.index', ['policies' => RefundPolicy::query()->with('translations')->orderByDesc('is_default')->orderBy('id')->get()]);
    }

    public function create(): View
    {
        return view('digital-products::admin.policies.form', ['policy' => null, 'locales' => $this->locales()]);
    }

    public function edit(string $policy): View
    {
        return view('digital-products::admin.policies.form', ['policy' => RefundPolicy::query()->where('public_id', $policy)->with('translations')->firstOrFail(), 'locales' => $this->locales()]);
    }

    public function store(SavePolicyRequest $request, SaveRefundPolicy $save): RedirectResponse
    {
        return $this->persist($request, $save, null);
    }

    public function update(SavePolicyRequest $request, SaveRefundPolicy $save, string $policy): RedirectResponse
    {
        return $this->persist($request, $save, RefundPolicy::query()->where('public_id', $policy)->firstOrFail());
    }

    private function persist(SavePolicyRequest $request, SaveRefundPolicy $save, ?RefundPolicy $policy): RedirectResponse
    {
        try {
            $saved = $save->handle(
                $this->actor($request), $policy, (string) $request->validated('rule'), $request->validated('days') === null ? null : (int) $request->validated('days'),
                $request->boolean('revoke'), $request->boolean('is_default'), (array) $request->validated('words'),
            );
        } catch (DigitalException $e) {
            return back()->withErrors(['policy' => __('digital-products::errors.'.$e->reason, $e->params)])->withInput();
        }

        return redirect()->route('admin.digital.policies.edit', $saved->public_id)->with('status', __('digital-products::admin.policies.saved'));
    }

    /** @return list<string> */
    private function locales(): array
    {
        return array_values(array_map('strval', DB::table('languages')->where('is_active', true)->orderByDesc('is_default')->pluck('code')->all()));
    }
}
