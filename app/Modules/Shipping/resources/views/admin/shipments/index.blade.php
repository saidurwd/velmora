@extends('layouts.admin')

@section('title', __('shipping::fulfilment.shipments.title'))

@php
    $fmt = fn (int $minor, string $cur = 'BDT') => \App\Modules\Platform\Support\Fmt::money(\App\Modules\Platform\DTOs\Money::of($minor, $cur));
@endphp

@section('content')
<div class="space-y-6">
    <h1 class="text-2xl font-bold">{{ __('shipping::fulfilment.shipments.title') }}</h1>
    @include('shipping::admin._alerts')

    <form method="GET" action="{{ route('admin.shipping.shipments.index') }}" class="grid gap-3 sm:grid-cols-4 sm:items-end">
        <div><label for="status" class="block text-sm font-medium">{{ __('shipping::fulfilment.shipments.status') }}</label>
            <select id="status" name="status" class="block w-full rounded-md border border-line px-3 py-2"><option value="">{{ __('shipping::fulfilment.all') }}</option>
                @foreach ($statuses as $s)<option value="{{ $s->value }}" @selected($status === $s)>{{ __('shipping::fulfilment.status.'.$s->value) }}</option>@endforeach</select></div>
        <div><label for="courier" class="block text-sm font-medium">{{ __('shipping::fulfilment.shipments.courier') }}</label>
            <select id="courier" name="courier" class="block w-full rounded-md border border-line px-3 py-2"><option value="">{{ __('shipping::fulfilment.all') }}</option>
                @foreach ($couriers as $c)<option value="{{ $c }}" @selected($courier === $c)>{{ $c }}</option>@endforeach</select></div>
        <div><label for="q" class="block text-sm font-medium">{{ __('shipping::fulfilment.search') }}</label><input id="q" name="q" value="{{ $search }}" maxlength="80" class="block w-full rounded-md border border-line px-3 py-2"></div>
        <div><x-platform::btn type="submit" size="sm">{{ __('shipping::fulfilment.filter') }}</x-platform::btn></div>
    </form>

    @if ($parcels->isEmpty())
        <p class="text-muted">{{ __('shipping::fulfilment.shipments.empty') }}</p>
    @else
    <div class="overflow-x-auto"><table class="w-full text-sm">
        <caption class="sr-only">{{ __('shipping::fulfilment.shipments.title') }}</caption>
        <thead><tr class="border-b border-line">@foreach (['parcel', 'order', 'courier', 'tracking', 'status', 'cash', 'made'] as $col)<th scope="col" class="py-2 pe-3 text-start">{{ __('shipping::fulfilment.shipments.cols.'.$col) }}</th>@endforeach</tr></thead>
        <tbody>
        @foreach ($parcels as $p)
            <tr class="border-b border-line">
                <td class="py-2 pe-3"><a class="underline" href="{{ route('admin.shipping.shipments.show', $p->public_id) }}">{{ strtoupper(substr($p->public_id, -8)) }}</a></td>
                <td class="py-2 pe-3">@if (isset($orders[$p->order_id]))<a class="underline" href="{{ route('admin.shipping.orders.show', $orders[$p->order_id]['public_id']) }}">{{ $orders[$p->order_id]['number'] }}</a>@endif</td>
                <td class="py-2 pe-3">{{ $p->courier }}</td><td class="py-2 pe-3">{{ $p->tracking_code ?? __('shipping::fulfilment.none') }}</td>
                <td class="py-2 pe-3"><x-platform::badge :tone="match ($p->status->value) { 'delivered' => 'success', 'failed', 'returned', 'unknown' => 'danger', 'cancelled' => 'neutral', default => 'brand' }">{{ __('shipping::fulfilment.status.'.$p->status->value) }}</x-platform::badge></td>
                <td class="py-2 pe-3">{{ $p->cod_amount_minor > 0 ? $fmt($p->cod_amount_minor, $p->currency) : __('shipping::fulfilment.none') }}</td>
                <td class="py-2 pe-3">{{ \App\Modules\Platform\Support\Fmt::dateTime($p->created_at) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    {{ $parcels->links() }}
    @endif
</div>
@endsection
