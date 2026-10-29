<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Controllers\Admin;

use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Shipping\Actions\SaveShippingMethod;
use App\Modules\Shipping\Http\Requests\MethodRequest;
use App\Modules\Shipping\Models\ShippingMethod;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class MethodController
{
    public function index(): View
    {
        return view('shipping::admin.methods.index', ['methods' => ShippingMethod::query()->with('translations')->orderBy('sort_order')->orderBy('id')->get()]);
    }

    public function create(): View
    {
        return view('shipping::admin.methods.form', ['method' => null]);
    }

    public function store(MethodRequest $request, SaveShippingMethod $save): RedirectResponse
    {
        return $this->persist($request, $save, null);
    }

    public function edit(ShippingMethod $method): View
    {
        return view('shipping::admin.methods.form', ['method' => $method->load('translations')]);
    }

    public function update(MethodRequest $request, ShippingMethod $method, SaveShippingMethod $save): RedirectResponse
    {
        return $this->persist($request, $save, $method);
    }

    private function persist(MethodRequest $request, SaveShippingMethod $save, ?ShippingMethod $method): RedirectResponse
    {
        $user = $request->user('admin');
        abort_if($user === null, 403);

        $save->handle(
            new Actor(ActorType::Staff, (int) $user->getAuthIdentifier(), $user->public_id, $user->name), $method, $request->validated('code'), $request->translations(),
            $request->boolean('cod_available'), $request->filled('cod_fee') ? (int) $request->money((string) $request->input('cod_fee')) : 0,
            $request->filled('free_over') ? $request->money((string) $request->input('free_over')) : null, $request->boolean('is_active'), (int) $request->validated('sort_order', 0),
        );

        return redirect()->route('admin.shipping.methods.index')->with('status', __('shipping::admin.saved'));
    }
}
