@extends('layouts.admin')

@section('title', __('inventory::screens.aging.title'))

@section('content')
<div class="space-y-8">
    <h1 class="text-2xl font-bold">{{ __('inventory::screens.aging.title') }}</h1>
    <p class="text-sm text-muted">{{ __('inventory::screens.aging.help') }}</p>
    <section aria-labelledby="b-h" class="space-y-2"><h2 id="b-h" class="text-lg font-semibold">{{ __('inventory::screens.aging.buckets') }}</h2>
        <div class="overflow-x-auto"><table class="w-full text-sm">
            <caption class="sr-only">{{ __('inventory::screens.aging.buckets') }}</caption>
            <thead><tr class="border-b border-line"><th scope="col" class="py-2 pe-3 text-start">{{ __('inventory::screens.aging.days') }}</th><th scope="col" class="py-2 pe-3 text-start">{{ __('inventory::screens.aging.lines') }}</th><th scope="col" class="py-2 pe-3 text-start">{{ __('inventory::screens.aging.units') }}</th></tr></thead>
            <tbody>@foreach ($report['buckets'] as $bucket => $b)<tr class="border-b border-line"><td class="py-2 pe-3">{{ $bucket }}</td><td class="py-2 pe-3">{{ $b['lines'] }}</td><td class="py-2 pe-3">{{ $b['units'] }}</td></tr>@endforeach</tbody>
        </table></div></section>
    <section aria-labelledby="o-h" class="space-y-2"><h2 id="o-h" class="text-lg font-semibold">{{ __('inventory::screens.aging.oldest') }}</h2>
        @if ($report['oldest'] === [])<p class="text-muted">{{ __('inventory::screens.aging.empty') }}</p>@else
        <div class="overflow-x-auto"><table class="w-full text-sm">
            <caption class="sr-only">{{ __('inventory::screens.aging.oldest') }}</caption>
            <thead><tr class="border-b border-line">@foreach (['sku', 'product', 'place', 'units', 'days'] as $col)<th scope="col" class="py-2 pe-3 text-start">{{ __('inventory::screens.aging.cols.'.$col) }}</th>@endforeach</tr></thead>
            <tbody>@foreach ($report['oldest'] as $r)<tr class="border-b border-line"><td class="py-2 pe-3">{{ $variants[$r['variant_id']]->sku ?? '#'.$r['variant_id'] }}</td><td class="py-2 pe-3">{{ $variants[$r['variant_id']]->productName ?? '' }}</td><td class="py-2 pe-3">{{ $r['location'] }}</td><td class="py-2 pe-3">{{ $r['units'] }}</td><td class="py-2 pe-3">{{ $r['days'] }}</td></tr>@endforeach</tbody>
        </table></div>@endif</section>
</div>
@endsection
