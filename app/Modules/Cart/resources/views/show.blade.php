@extends('layouts.storefront')

@section('title', __('cart::cart.title'))

@php($fmt = fn ($m) => \App\Modules\Platform\Support\Fmt::money($m))

@section('content')
<div class="space-y-6">
    <h1 class="text-3xl font-bold">{{ __('cart::cart.title') }}</h1>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    @error('cart')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror

    @if ($cart->isEmpty())
        <x-platform::empty-state :title="__('cart::cart.empty_title')" :text="__('cart::cart.empty_text')">
            <x-platform::btn :href="url('/'.app()->getLocale().'/shop')">{{ __('cart::cart.continue') }}</x-platform::btn>
        </x-platform::empty-state>
    @else
        @if ($cart->hasPriceChanges())
            <x-platform::alert>
                <p class="font-medium">{{ __('cart::cart.price_changed_title') }}</p>
                <p>{{ __('cart::cart.price_changed_text') }}</p>
                <form method="POST" action="{{ route('cart.acknowledge', ['locale' => app()->getLocale()]) }}" class="mt-2">@csrf<x-platform::btn type="submit" size="sm" variant="secondary">{{ __('cart::cart.price_changed_ok') }}</x-platform::btn></form>
            </x-platform::alert>
        @endif

        <ul class="divide-y divide-line rounded-lg border border-line">
            @foreach ($cart->lines as $line)
                <li class="grid gap-3 p-4 sm:grid-cols-[5rem_1fr_auto]">
                    <div class="size-20 overflow-hidden rounded-md bg-surface-alt">@if ($line->imageUrl)<img src="{{ $line->imageUrl }}" alt="" width="80" height="80" loading="lazy" class="size-full object-cover">@endif</div>
                    <div class="space-y-1">
                        <p class="font-medium">@if ($line->productSlug !== '')<a class="hover:underline" href="{{ url('/'.app()->getLocale().'/p/'.rawurlencode($line->productSlug)) }}">{{ $line->name }}</a>@else{{ $line->name ?: __('cart::cart.removed_product') }}@endif</p>
                        @if ($line->optionsLabel)<p class="text-sm text-muted">{{ $line->optionsLabel }}</p>@endif
                        @if ($line->unitPrice)<p class="text-sm">{{ $fmt($line->unitPrice) }} <span class="text-muted">× {{ \App\Modules\Platform\Support\Fmt::number($line->quantity) }}</span></p>@endif
                        @if ($line->priceChangedFrom && $line->unitPrice)<p class="text-sm text-warning">{{ __('cart::cart.was_now', ['was' => $fmt($line->priceChangedFrom), 'now' => $fmt($line->unitPrice)]) }}</p>@endif
                        @if ($line->problem === 'unavailable')<p class="text-sm text-danger">{{ __('cart::cart.problem_unavailable') }}</p>@endif
                        @if ($line->problem === 'insufficient_stock')<p class="text-sm text-danger">{{ trans_choice('cart::cart.problem_stock', (int) $line->available, ['available' => \App\Modules\Platform\Support\Fmt::number((int) $line->available)]) }}</p>@endif
                    </div>
                    <div class="space-y-2 sm:text-end">
                        @if ($line->lineTotal)<p class="font-semibold">{{ $fmt($line->lineTotal) }}</p>@endif
                        @if ($line->problem !== 'unavailable')
                            <form method="POST" action="{{ route('cart.update', ['locale' => app()->getLocale(), 'variant' => $line->variantPublicId]) }}" class="flex items-end gap-2 sm:justify-end">
                                @csrf @method('PATCH')
                                <div><label for="q-{{ $line->variantPublicId }}" class="block text-xs text-muted">{{ __('cart::cart.quantity') }}</label>
                                    <input id="q-{{ $line->variantPublicId }}" name="quantity" inputmode="numeric" value="{{ $line->quantity }}" class="w-20 rounded-md border border-line px-2 py-2 text-center"></div>
                                <x-platform::btn type="submit" size="sm" variant="secondary">{{ __('cart::cart.update') }}</x-platform::btn>
                            </form>
                        @endif
                        <form method="POST" action="{{ route('cart.remove', ['locale' => app()->getLocale(), 'variant' => $line->variantPublicId ?: 'x']) }}">@csrf @method('DELETE')<button class="inline-flex min-h-9 min-w-9 items-center justify-center px-2 text-sm underline">{{ __('cart::cart.remove') }}</button></form>
                    </div>
                </li>
            @endforeach
        </ul>

        <section aria-labelledby="sum-h" class="ms-auto max-w-sm space-y-2 rounded-lg border border-line p-4">
            <h2 id="sum-h" class="sr-only">{{ __('cart::cart.summary') }}</h2>
            <p class="flex justify-between text-lg font-semibold"><span>{{ __('cart::cart.subtotal') }}</span><span>{{ $fmt($cart->subtotal) }}</span></p>
            @foreach ($cart->discounts?->applied ?? [] as $d)
                @if ($d->amountMinor > 0)<p class="flex justify-between text-sm"><span>{{ $d->label }}@if ($d->code) <span class="text-muted">({{ $d->code }})</span>@endif</span><span>−{{ $fmt(\App\Modules\Platform\DTOs\Money::of($d->amountMinor)) }}</span></p>@endif
                @if ($d->freeShipping)<p class="flex justify-between text-sm"><span>{{ __('cart::cart.free_delivery') }} <span class="text-muted">({{ $d->code }})</span></span></p>@endif
            @endforeach
            @if ($cart->discounts && $cart->discountTotal()->amountMinor > 0)
                <p class="flex justify-between font-medium"><span>{{ __('cart::cart.goods_total') }}</span><span>{{ $fmt($cart->goodsTotal()) }}</span></p>
            @endif
            @foreach ($cart->discounts?->rejected ?? [] as $r)
                <p class="text-sm text-danger" role="status">{{ __('cart::cart.code_not_valid') }} {{ __('pricing::coupons.reasons.'.$r['reason'], ['code' => $r['code'], 'min' => $fmt(\App\Modules\Platform\DTOs\Money::of($r['min'] ?? 0))]) }}
                    <form method="POST" action="{{ route('cart.coupon.remove', ['locale' => app()->getLocale(), 'code' => $r['code']]) }}" class="inline">@csrf @method('DELETE')<button class="inline-flex min-h-9 items-center px-2 underline" aria-label="{{ __('cart::cart.coupon_remove', ['code' => $r['code']]) }}">{{ __('cart::cart.remove') }}</button></form></p>
            @endforeach
            <form method="POST" action="{{ route('cart.coupon.apply', ['locale' => app()->getLocale()]) }}" class="space-y-1">
                @csrf
                <label for="coupon-code" class="block text-sm font-medium">{{ __('cart::cart.coupon_label') }}</label>
                <div class="flex gap-2">
                    <input id="coupon-code" name="code" value="{{ old('code') }}" autocomplete="off" autocapitalize="characters" maxlength="100" class="min-w-0 flex-1 rounded-md border border-line px-3 py-2" @error('coupon') aria-invalid="true" aria-describedby="coupon-error" @enderror>
                    <x-platform::btn type="submit" size="sm" variant="secondary">{{ __('cart::cart.coupon_apply') }}</x-platform::btn>
                </div>
                @error('coupon')<p id="coupon-error" class="text-sm text-danger">{{ $message }}</p>@enderror
            </form>
            @foreach (array_filter($cart->discounts?->applied ?? [], fn ($d) => $d->code) as $d)
                <form method="POST" action="{{ route('cart.coupon.remove', ['locale' => app()->getLocale(), 'code' => $d->code]) }}" class="text-sm">@csrf @method('DELETE')<button class="inline-flex min-h-9 items-center px-2 underline" aria-label="{{ __('cart::cart.coupon_remove', ['code' => $d->code]) }}">{{ __('cart::cart.coupon_remove', ['code' => $d->code]) }}</button></form>
            @endforeach
            <p class="text-sm text-muted">{{ __('cart::cart.shipping_later') }}</p>
            @if ($cart->hasProblems())
                <x-platform::btn type="button" disabled aria-disabled="true" class="w-full cursor-not-allowed opacity-60">{{ __('cart::cart.checkout') }}</x-platform::btn>
                <p class="text-sm text-danger">{{ __('cart::cart.fix_first') }}</p>
            @else
                <x-platform::btn :href="route('checkout.details', ['locale' => app()->getLocale()])" class="w-full">{{ __('cart::cart.checkout') }}</x-platform::btn>
            @endif
        </section>
    @endif
</div>
@endsection
