@extends('layouts.storefront')

@section('title', __('orders::checkout.review_title'))

@php
    $fmt = fn (int $minor) => \App\Modules\Platform\Support\Fmt::money(\App\Modules\Platform\DTOs\Money::of($minor, $review->quote->breakdown->currency));
    $b = $review->quote->breakdown;
    $locale = app()->getLocale();
    $canPlace = $review->quote->paymentMethods !== [] && (! $review->quote->shipping->required || $review->quote->option !== null);
@endphp

@section('content')
<div class="space-y-6">
    <nav aria-label="{{ __('orders::checkout.steps') }}"><ol class="flex gap-4 text-sm text-muted">
        <li>1. {{ __('orders::checkout.step_details') }}</li><li aria-current="step" class="font-semibold text-ink">2. {{ __('orders::checkout.step_review') }}</li><li>3. {{ __('orders::checkout.step_done') }}</li>
    </ol></nav>
    <h1 class="text-3xl font-bold">{{ __('orders::checkout.review_title') }}</h1>
    @error('checkout')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror

    <div class="grid gap-8 lg:grid-cols-[1fr_22rem]">
        <div class="space-y-6">
        <form method="POST" action="{{ route('checkout.place', ['locale' => $locale]) }}" class="space-y-6" id="place-form">
            @csrf
            <input type="hidden" name="place_token" value="{{ $token }}">
            <input type="hidden" name="expected_total" value="{{ $b->grandTotalMinor }}">

            <section aria-labelledby="who-h" class="space-y-1">
                <h2 id="who-h" class="text-lg font-semibold">{{ $review->needsDelivery ? __('orders::checkout.deliver_to') : __('orders::checkout.contact') }}</h2>
                <p>{{ $review->contactName }} · {{ $review->contactPhone }}@if ($review->contactEmail) · {{ $review->contactEmail }}@endif</p>
                @if ($review->address)<p class="text-muted">{{ $review->address->oneLine($locale) }}</p>@if ($review->address->deliveryNote)<p class="text-sm text-muted">{{ $review->address->deliveryNote }}</p>@endif @endif
                <a class="text-sm underline" href="{{ route('checkout.details', ['locale' => $locale]) }}">{{ __('orders::checkout.change') }}</a>
            </section>

            @if ($review->quote->shipping->required)
                <fieldset class="space-y-2">
                    <legend class="text-lg font-semibold">{{ __('orders::checkout.delivery') }}</legend>
                    @forelse ($review->quote->shipping->options as $o)
                        <label class="flex items-start gap-3 rounded-lg border border-line p-3">
                            <input type="radio" name="shipping" value="{{ $o->methodCode }}" class="mt-1" @checked($review->shippingCode === $o->methodCode)>
                            <span class="flex-1"><span class="block font-medium">{{ $o->name }}</span>@if ($o->deliveryEstimate)<span class="block text-sm text-muted">{{ $o->deliveryEstimate }}</span>@endif</span>
                            <span class="font-medium">{{ $o->isFree ? __('orders::checkout.free') : $fmt($o->price->amountMinor) }}</span>
                        </label>
                    @empty
                        <x-platform::alert type="error">{{ __('orders::checkout.errors.cannot_deliver') }}</x-platform::alert>
                    @endforelse
                </fieldset>
            @endif

            <fieldset class="space-y-2">
                <legend class="text-lg font-semibold">{{ __('orders::checkout.payment') }}</legend>
                @forelse ($review->quote->paymentMethods as $m)
                    <label class="flex items-start gap-3 rounded-lg border border-line p-3">
                        <input type="radio" name="payment" value="{{ $m }}" class="mt-1" @checked($review->paymentCode === $m)>
                        <span class="flex-1"><span class="block font-medium">{{ __('orders::checkout.methods.'.$m) }}</span><span class="block text-sm text-muted">{{ __('orders::checkout.methods_help.'.$m) }}</span></span>
                        @if ($m === 'cod' && $b->codFeeMinor > 0)<span class="font-medium">+{{ $fmt($b->codFeeMinor) }}</span>@endif
                    </label>
                @empty
                    <x-platform::alert type="error">{{ $review->needsDelivery ? __('orders::checkout.errors.payment_method_invalid') : __('orders::checkout.errors.no_online_payment') }}</x-platform::alert>
                @endforelse
            </fieldset>

            <div class="flex flex-wrap gap-3">
                <button type="submit" formaction="{{ route('checkout.choose', ['locale' => $locale]) }}" class="min-h-11 rounded-md border border-line px-5 py-2">{{ __('orders::checkout.update_totals') }}</button>
            </div>

        </form>

        <div class="space-y-4">
            @if ($review->needsPhoneConfirmation)
                <section aria-labelledby="code-h" class="space-y-3 rounded-lg border border-line p-4">
                    <h2 id="code-h" class="text-lg font-semibold">{{ __('orders::checkout.code.title') }}</h2>
                    @if (session('code_status'))<x-platform::alert type="success">{{ session('code_status') }}</x-platform::alert>@endif
                    @if (session('code_problem'))<x-platform::alert type="error">{{ session('code_problem') }}</x-platform::alert>@endif
                    @if ($phoneConfirmed)
                        <p class="font-medium">{{ __('orders::checkout.code.done', ['phone' => $maskedPhone]) }}</p>
                    @else
                        <p>{{ __('orders::checkout.code.text', ['phone' => $maskedPhone]) }}</p>
                        <form method="POST" action="{{ route('checkout.phone.send', ['locale' => $locale]) }}">@csrf<button class="min-h-11 rounded-md border border-line px-5 py-2">{{ __('orders::checkout.code.send') }}</button></form>
                        <form method="POST" action="{{ route('checkout.phone.verify', ['locale' => $locale]) }}" class="space-y-2">
                            @csrf
                            <x-platform::form.field name="code" inputmode="numeric" autocomplete="one-time-code" :label="__('orders::checkout.code.label')" required />
                            <x-platform::form.button>{{ __('orders::checkout.code.verify') }}</x-platform::form.button>
                        </form>
                    @endif
                </section>
            @endif
            <div class="space-y-2 border-t border-line pt-4">
                <p class="text-sm text-muted">{{ __('orders::checkout.place_note') }}</p>
                <button type="submit" form="place-form" @disabled(! $canPlace || ($review->needsPhoneConfirmation && ! $phoneConfirmed)) class="inline-flex min-h-11 items-center rounded-md bg-brand-600 px-6 py-2 font-medium text-white hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-60">{{ __('orders::checkout.place_order', ['total' => $fmt($b->grandTotalMinor)]) }}</button>
            </div>
        </div>
        </div>

        <aside aria-labelledby="sum-h" class="h-fit space-y-2 rounded-lg border border-line p-4">
            <h2 id="sum-h" class="text-lg font-semibold">{{ __('orders::checkout.your_order') }}</h2>
            <ul class="space-y-1 text-sm">@foreach ($review->cart->lines as $l)<li class="flex justify-between gap-2"><span>{{ $l->name }}@if ($l->optionsLabel) <span class="text-muted">({{ $l->optionsLabel }})</span>@endif × {{ \App\Modules\Platform\Support\Fmt::number($l->quantity) }}</span><span>{{ $l->lineTotal ? $fmt($l->lineTotal->amountMinor) : '' }}</span></li>@endforeach</ul>
            <dl class="space-y-1 border-t border-line pt-2 text-sm">
                <div class="flex justify-between"><dt>{{ __('cart::cart.subtotal') }}</dt><dd>{{ $fmt($b->subtotalMinor) }}</dd></div>
                @if ($b->discountMinor() > 0)<div class="flex justify-between"><dt>{{ __('cart::cart.discount') }}</dt><dd>−{{ $fmt($b->discountMinor()) }}</dd></div>@endif
                <div class="flex justify-between"><dt>{{ __('orders::checkout.delivery') }}</dt><dd>{{ $b->shippingMinor === 0 && $review->quote->shipping->required ? __('orders::checkout.free') : $fmt($b->shippingMinor) }}</dd></div>
                @if ($b->codFeeMinor > 0)<div class="flex justify-between"><dt>{{ __('orders::checkout.cod_fee') }}</dt><dd>{{ $fmt($b->codFeeMinor) }}</dd></div>@endif
                @if ($b->taxMinor > 0)<div class="flex justify-between"><dt>{{ $b->taxInclusive ? __('orders::checkout.tax_included') : __('orders::checkout.tax') }}</dt><dd>{{ $fmt($b->taxMinor) }}</dd></div>@endif
                <div class="flex justify-between border-t border-line pt-2 text-base font-semibold"><dt>{{ __('orders::checkout.total') }}</dt><dd>{{ $fmt($b->grandTotalMinor) }}</dd></div>
            </dl>
            <a class="text-sm underline" href="{{ route('cart.show', ['locale' => $locale]) }}">{{ __('orders::checkout.edit_cart') }}</a>
        </aside>
    </div>
</div>
@endsection
