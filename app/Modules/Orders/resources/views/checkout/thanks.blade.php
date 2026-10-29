@extends('layouts.storefront')

@section('title', __('orders::checkout.thanks_title'))

@php
    $locale = app()->getLocale();
    $fmt = fn (int $minor) => \App\Modules\Platform\Support\Fmt::money(\App\Modules\Platform\DTOs\Money::of($minor, $order->currency));
    $address = $order->addresses->firstWhere('type', 'shipping');
    $phone = \App\Modules\Platform\DTOs\PhoneNumber::tryParse($order->contact_phone)?->masked() ?? '';
@endphp

@section('content')
<div class="mx-auto max-w-2xl space-y-6">
    <nav aria-label="{{ __('orders::checkout.steps') }}"><ol class="flex gap-4 text-sm text-muted">
        <li>1. {{ __('orders::checkout.step_details') }}</li><li>2. {{ __('orders::checkout.step_review') }}</li><li aria-current="step" class="font-semibold text-ink">3. {{ __('orders::checkout.step_done') }}</li>
    </ol></nav>
    <div class="space-y-2">
        <h1 class="text-3xl font-bold">{{ __('orders::checkout.thanks_title') }}</h1>
        <p class="text-lg">{{ __('orders::checkout.order_number') }}: <strong>{{ $order->order_number }}</strong></p>
        <p class="text-muted">{{ __('orders::checkout.keep_link') }}</p>
    </div>

    <x-platform::alert type="success">
        <p class="font-medium">{{ $order->payment_method_code === 'cod' ? __('orders::checkout.cod_message', ['total' => $fmt($order->grand_total_minor)]) : __('orders::checkout.received') }}</p>
        <p>{{ $order->status->value === 'pending' ? __('orders::checkout.we_will_confirm') : __('orders::checkout.confirmed') }}</p>
    </x-platform::alert>

    <section aria-labelledby="items-h" class="space-y-2">
        <h2 id="items-h" class="text-lg font-semibold">{{ __('orders::checkout.your_order') }}</h2>
        <ul class="divide-y divide-line rounded-lg border border-line">
            @foreach ($order->items as $item)
                <li class="flex justify-between gap-3 p-3 text-sm"><span>{{ $item->name($locale) }}@if ($item->options($locale)) <span class="text-muted">({{ $item->options($locale) }})</span>@endif × {{ \App\Modules\Platform\Support\Fmt::number($item->quantity) }}</span><span>{{ $fmt($item->line_total_minor) }}</span></li>
            @endforeach
        </ul>
        <dl class="ms-auto max-w-xs space-y-1 text-sm">
            <div class="flex justify-between"><dt>{{ __('cart::cart.subtotal') }}</dt><dd>{{ $fmt($order->subtotal_minor) }}</dd></div>
            @if ($order->discount_minor > 0)<div class="flex justify-between"><dt>{{ __('cart::cart.discount') }}</dt><dd>−{{ $fmt($order->discount_minor) }}</dd></div>@endif
            <div class="flex justify-between"><dt>{{ __('orders::checkout.delivery') }}</dt><dd>{{ $order->shipping_minor === 0 ? __('orders::checkout.free') : $fmt($order->shipping_minor) }}</dd></div>
            @if ($order->cod_fee_minor > 0)<div class="flex justify-between"><dt>{{ __('orders::checkout.cod_fee') }}</dt><dd>{{ $fmt($order->cod_fee_minor) }}</dd></div>@endif
            @if ($order->tax_minor > 0)<div class="flex justify-between"><dt>{{ $order->tax_inclusive ? __('orders::checkout.tax_included') : __('orders::checkout.tax') }}</dt><dd>{{ $fmt($order->tax_minor) }}</dd></div>@endif
            <div class="flex justify-between border-t border-line pt-1 text-base font-semibold"><dt>{{ __('orders::checkout.total') }}</dt><dd>{{ $fmt($order->grand_total_minor) }}</dd></div>
        </dl>
    </section>

    <section aria-labelledby="to-h" class="space-y-1">
        <h2 id="to-h" class="text-lg font-semibold">{{ $address ? __('orders::checkout.deliver_to') : __('orders::checkout.contact') }}</h2>
        <p>{{ $order->contact_name }} · {{ $phone }}</p>
        @if ($address)<p class="text-muted">{{ collect([$address->address_line, $address->area, $address->locationLine($locale), $address->postal_code])->filter()->join(', ') }}</p>@endif
    </section>

    <x-platform::btn :href="url('/'.$locale.'/shop')">{{ __('cart::cart.continue') }}</x-platform::btn>
</div>
@endsection
