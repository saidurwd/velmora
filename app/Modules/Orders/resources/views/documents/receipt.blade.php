@extends('layouts.print')

@section('title', __('orders::documents.receipt.title').' '.$receipt['number'])

@php
    $fmt = fn (int $minor) => \App\Modules\Platform\Support\Fmt::money(\App\Modules\Platform\DTOs\Money::of($minor, $receipt['currency']));
    $t = $receipt['totals'];
    $brand = app(\App\Modules\Platform\Support\Branding::class);
@endphp

@section('content')
<article class="mx-auto max-w-3xl space-y-6 p-6">
    @if ($back)<p class="print:hidden"><a class="text-sm underline" href="{{ $back }}">← {{ __('orders::documents.back') }}</a></p>@endif
    @if ($staff && ! $receipt['reconciles'])<x-platform::alert type="error">{{ __('orders::documents.receipt.not_reconciled') }}</x-platform::alert>@endif

    <header class="flex items-start justify-between gap-4">
        <div><p class="text-xl font-bold">{{ $brand->name() }}</p>@if ($brand->tagline())<p class="text-sm text-muted">{{ $brand->tagline() }}</p>@endif</div>
        <div class="text-end"><h1 class="text-2xl font-bold">{{ __('orders::documents.receipt.title') }}</h1>
            <p class="text-sm">{{ __('orders::documents.receipt.number') }}: <strong>{{ $receipt['number'] }}</strong></p>
            <p class="text-sm">{{ \App\Modules\Platform\Support\Fmt::date($receipt['placed_at']) }}</p>
            @if ($receipt['cancelled'])<p class="mt-1 inline-block rounded border-2 border-danger px-2 py-0.5 text-sm font-bold uppercase text-danger">{{ __('orders::documents.receipt.cancelled') }}</p>@endif</div>
    </header>

    <section class="grid gap-4 text-sm sm:grid-cols-2" aria-label="{{ __('orders::documents.receipt.parties') }}">
        <div><h2 class="font-semibold">{{ __('orders::documents.receipt.customer') }}</h2><p>{{ $receipt['customer']['name'] }}</p>@if ($receipt['customer']['email'])<p>{{ $receipt['customer']['email'] }}</p>@endif</div>
        @if ($receipt['address'])
        <div><h2 class="font-semibold">{{ __('orders::documents.receipt.deliver_to') }}</h2><p>{{ $receipt['address']['name'] }}</p><p>{{ $receipt['address']['line'] }}@if ($receipt['address']['area']), {{ $receipt['address']['area'] }}@endif</p><p>{{ $receipt['address']['district'] }}@if ($receipt['address']['postal_code']) {{ $receipt['address']['postal_code'] }}@endif</p></div>
        @endif
    </section>

    <table class="w-full text-sm">
        <caption class="sr-only">{{ __('orders::documents.receipt.items') }}</caption>
        <thead><tr class="border-b-2 border-ink">
            <th scope="col" class="py-2 pe-3 text-start">{{ __('orders::documents.receipt.item') }}</th><th scope="col" class="py-2 pe-3 text-end">{{ __('orders::documents.receipt.qty') }}</th>
            <th scope="col" class="py-2 pe-3 text-end">{{ __('orders::documents.receipt.unit') }}</th><th scope="col" class="py-2 text-end">{{ __('orders::documents.receipt.amount') }}</th>
        </tr></thead>
        <tbody>
        @foreach ($receipt['lines'] as $l)
            <tr class="border-b border-line align-top">
                <td class="py-2 pe-3">{{ $l['name'] }}@if ($l['options'])<br><span class="text-muted">{{ $l['options'] }}</span>@endif<br><span class="text-xs text-muted">{{ $l['sku'] }}</span>@if ($l['discount_minor'] > 0)<br><span class="text-xs">{{ __('orders::documents.receipt.line_discount') }}: −{{ $fmt($l['discount_minor']) }}</span>@endif</td>
                <td class="py-2 pe-3 text-end">{{ \App\Modules\Platform\Support\Fmt::number($l['quantity']) }}</td><td class="py-2 pe-3 text-end">{{ $fmt($l['unit_minor']) }}</td><td class="py-2 text-end">{{ $fmt($l['unit_minor'] * $l['quantity']) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <dl class="ms-auto max-w-sm space-y-1 text-sm">
        <div class="flex justify-between"><dt>{{ __('orders::orders.totals.subtotal') }}</dt><dd>{{ $fmt($t['subtotal_minor']) }}</dd></div>
        @if ($t['discount_minor'] > 0)<div class="flex justify-between"><dt>{{ __('orders::orders.totals.discount') }}</dt><dd>−{{ $fmt($t['discount_minor']) }}</dd></div>@endif
        <div class="flex justify-between"><dt>{{ __('orders::orders.totals.shipping') }}</dt><dd>{{ $fmt($t['shipping_minor']) }}</dd></div>
        @if ($t['cod_fee_minor'] > 0)<div class="flex justify-between"><dt>{{ __('orders::orders.totals.cod_fee') }}</dt><dd>{{ $fmt($t['cod_fee_minor']) }}</dd></div>@endif
        @if ($t['tax_minor'] > 0)<div class="flex justify-between"><dt>{{ $t['tax_inclusive'] ? __('orders::orders.totals.tax_included') : __('orders::orders.totals.tax_added') }}</dt><dd>{{ $fmt($t['tax_minor']) }}</dd></div>@endif
        <div class="flex justify-between border-t-2 border-ink pt-1 text-base font-bold"><dt>{{ __('orders::orders.totals.grand') }}</dt><dd>{{ $fmt($t['grand_minor']) }}</dd></div>
    </dl>

    <p class="text-sm">{{ __('orders::documents.receipt.payment') }}: {{ $receipt['payment']['method'] }} — {{ __('orders::documents.receipt.payment_status.'.$receipt['payment']['status']) }}</p>
    <p class="text-xs text-muted">{{ __('orders::documents.receipt.not_tax_invoice') }}</p>
    <x-platform::business-block />
    <p class="print:hidden"><button type="button" data-print class="rounded-md border border-line px-4 py-2 text-sm hover:bg-surface-alt">{{ __('orders::documents.print') }}</button></p>
</article>
@endsection
