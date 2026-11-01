@extends('layouts.admin')

@section('title', __('inventory::screens.low.title'))

@section('content')
<div class="space-y-6">
    <h1 class="text-2xl font-bold">{{ __('inventory::screens.low.title') }}</h1>
    <p class="text-sm text-muted">{{ __('inventory::screens.low.help') }}</p>
    <form method="GET" action="{{ route('admin.inventory.reports.low') }}" class="flex items-end gap-3">
        <div><label for="place" class="block text-sm font-medium">{{ __('inventory::screens.location') }}</label>
            <select id="place" name="place" class="block rounded-md border border-line px-3 py-2"><option value="">{{ __('inventory::screens.all') }}</option>@foreach ($places as $p)<option value="{{ $p->code }}" @selected($place === $p->code)>{{ $p->name }}</option>@endforeach</select></div>
        <x-platform::btn type="submit" size="sm">{{ __('inventory::screens.filter') }}</x-platform::btn>
    </form>
    @if ($rows === [])
        <p class="text-muted">{{ __('inventory::screens.low.empty') }}</p>
    @else
    <div class="overflow-x-auto"><table class="w-full text-sm">
        <caption class="sr-only">{{ __('inventory::screens.low.title') }}</caption>
        <thead><tr class="border-b border-line">@foreach (['sku', 'product', 'place', 'on_hand', 'reserved', 'available', 'threshold'] as $col)<th scope="col" class="py-2 pe-3 text-start">{{ __('inventory::screens.low.cols.'.$col) }}</th>@endforeach</tr></thead>
        <tbody>
        @foreach ($rows as $r)
            <tr class="border-b border-line"><td class="py-2 pe-3">@if (isset($variants[$r['variant_id']]))<a class="underline" href="{{ route('admin.inventory.show', $variants[$r['variant_id']]->sku) }}">{{ $variants[$r['variant_id']]->sku }}</a>@endif</td>
                <td class="py-2 pe-3">{{ $variants[$r['variant_id']]->productName ?? '' }}</td><td class="py-2 pe-3">{{ $r['location'] }}</td><td class="py-2 pe-3">{{ $r['on_hand'] }}</td><td class="py-2 pe-3">{{ $r['reserved'] }}</td><td class="py-2 pe-3">{{ $r['available'] }}</td><td class="py-2 pe-3">{{ $r['threshold'] }}</td></tr>
        @endforeach
        </tbody>
    </table></div>
    @endif
</div>
@endsection
