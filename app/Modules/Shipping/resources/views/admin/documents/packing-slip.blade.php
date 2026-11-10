@extends('layouts.print')

@section('title', __('shipping::fulfilment.documents.slip.title').' '.strtoupper(substr($parcel->public_id, -8)))

@php
    $slip = 'shipping::fulfilment.documents.slip.';
    $fmt = fn (int $minor, string $cur) => \App\Modules\Platform\Support\Fmt::money(\App\Modules\Platform\DTOs\Money::of($minor, $cur));
    $brand = app(\App\Modules\Platform\Support\Branding::class);
@endphp

@section('content')
<article class="mx-auto max-w-3xl space-y-6 p-6">
    <p class="print:hidden"><a class="text-sm underline" href="{{ $back }}">← {{ __('shipping::fulfilment.documents.back') }}</a></p>
    <header class="flex items-start justify-between gap-4">
        <p class="text-xl font-bold">{{ $brand->name() }}</p>
        <div class="text-end"><h1 class="text-2xl font-bold">{{ __($slip.'title') }}</h1>
            <p class="text-sm">{{ __($slip.'parcel') }}: <strong>{{ strtoupper(substr($parcel->public_id, -8)) }}</strong></p><p class="text-sm">{{ __($slip.'order') }}: <strong>{{ $order->number }}</strong></p></div>
    </header>

    <section class="grid gap-4 text-sm sm:grid-cols-2">
        <div><h2 class="font-semibold">{{ __($slip.'deliver_to') }}</h2><p>{{ $order->contactName }}</p><p>{{ $order->addressLine }}@if ($order->area), {{ $order->area }}@endif</p><p>{{ $order->district }}@if ($order->postalCode) {{ $order->postalCode }}@endif</p><p>{{ __($slip.'phone') }}: {{ $order->contactPhone }}</p></div>
        <div><p>{{ __($slip.'courier') }}: {{ $parcel->courier }}</p><p>{{ __($slip.'tracking') }}: {{ $parcel->tracking_code ?? __('shipping::fulfilment.none') }}</p>
            <p class="mt-2 text-base font-bold">{{ $parcel->cod_amount_minor > 0 ? __($slip.'cash').': '.$fmt($parcel->cod_amount_minor, $parcel->currency) : __($slip.'prepaid') }}</p></div>
    </section>

    <table class="w-full text-sm">
        <caption class="sr-only">{{ __($slip.'items') }}</caption>
        <thead><tr class="border-b-2 border-ink"><th scope="col" class="py-2 pe-3 text-start">{{ __($slip.'item') }}</th><th scope="col" class="py-2 pe-3 text-end">{{ __($slip.'qty') }}</th><th scope="col" class="py-2 text-end">{{ __($slip.'packed') }}</th></tr></thead>
        <tbody>
        @foreach ($parcel->items as $i)
            <tr class="border-b border-line"><td class="py-2 pe-3">{{ $labels[$i->order_item_id]['name'] ?? '' }}<br><span class="text-xs text-muted">{{ $labels[$i->order_item_id]['sku'] ?? '' }}</span></td><td class="py-2 pe-3 text-end">{{ \App\Modules\Platform\Support\Fmt::number($i->quantity) }}</td><td class="py-2 text-end" aria-hidden="true">☐</td></tr>
        @endforeach
        </tbody>
    </table>
    <p class="text-sm text-muted">{{ __($slip.'note') }}</p>
    <x-platform::business-block />
    <p class="print:hidden"><button type="button" x-data @click="window.print()" class="rounded-md border border-line px-4 py-2 text-sm hover:bg-surface-alt">{{ __('shipping::fulfilment.documents.print') }}</button></p>
</article>
@endsection
