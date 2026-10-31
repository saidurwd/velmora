@extends('layouts.account')

@section('title', __('orders::account.title').' '.$order->order_number)

@php
    $locale = app()->getLocale();
    $fmt = fn (int $minor) => \App\Modules\Platform\Support\Fmt::money(\App\Modules\Platform\DTOs\Money::of($minor, $order->currency));
    $address = $order->addresses->firstWhere('type', 'shipping');
    $state = $order->payment_status->value;
@endphp

@section('account')
<p><a class="underline" href="{{ route('account.orders') }}">{{ __('orders::account.back') }}</a></p>
<h1 class="text-2xl font-bold">{{ $order->order_number }}</h1>
<p class="text-muted">{{ \App\Modules\Platform\Support\Fmt::dateTime($order->placed_at) }} · {{ __('orders::orders.status.'.$order->status->value) }} · {{ __('orders::orders.payment.'.$state) }}</p>
@error('cancel')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror

@if ($payUrl)
    <x-platform::alert type="info">
        <p class="font-medium">{{ $state === 'pending' ? __('orders::account.payment_in_progress') : __('orders::account.pay_help') }}</p>
        <div class="mt-2"><x-platform::btn :href="$payUrl">{{ $state === 'unpaid' ? __('orders::account.pay_now') : __('orders::account.pay_again') }}</x-platform::btn></div>
    </x-platform::alert>
@endif
@if (in_array($state, ['refunded', 'partially_refunded'], true))<x-platform::alert type="success"><p>{{ __('orders::account.refunded_note') }}</p></x-platform::alert>@endif
@if ($online)<p class="text-sm text-muted">{{ __('orders::account.paid_with', ['method' => $methodLabel]) }}</p>@endif

<section aria-labelledby="items-h" class="space-y-2">
    <h2 id="items-h" class="text-lg font-semibold">{{ __('orders::account.items') }}</h2>
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

@if ($address)
<section aria-labelledby="to-h" class="space-y-1">
    <h2 id="to-h" class="text-lg font-semibold">{{ __('orders::account.deliver_to') }}</h2>
    <p>{{ $order->contact_name }}</p>
    <p class="text-muted">{{ collect([$address->address_line, $address->area, $address->locationLine($locale), $address->postal_code])->filter()->join(', ') }}</p>
</section>
@endif

@if ($notes->isNotEmpty())
<section aria-labelledby="notes-h" class="space-y-2">
    <h2 id="notes-h" class="text-lg font-semibold">{{ __('orders::account.notes') }}</h2>
    @foreach ($notes as $note)<p class="rounded-md border border-line p-3 text-sm"><span class="text-muted">{{ \App\Modules\Platform\Support\Fmt::dateTime($note->created_at) }}</span><br>{{ $note->body }}</p>@endforeach
</section>
@endif

@if ($cancelDenied === null)
<section aria-labelledby="cancel-h">
    <form method="POST" action="{{ route('account.orders.cancel', $order->public_id) }}" class="space-y-3 rounded-lg border border-line p-4">
        @csrf
        <h2 id="cancel-h" class="text-lg font-semibold">{{ __('orders::account.cancel_title') }}</h2>
        <p class="text-sm text-muted">{{ __('orders::account.cancel_text') }}</p>
        <div><label for="note" class="block text-sm font-medium">{{ __('orders::account.cancel_note') }}</label>
            <input id="note" name="note" maxlength="150" value="{{ old('note') }}" class="block w-full rounded-md border border-line px-3 py-2"></div>
        <x-platform::btn type="submit" variant="danger">{{ __('orders::account.cancel_btn') }}</x-platform::btn>
    </form>
</section>
@endif
@endsection
