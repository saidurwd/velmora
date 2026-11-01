@extends('layouts.admin')

@section('title', __('shipping::fulfilment.show.title', ['id' => strtoupper(substr($parcel->public_id, -8))]))

@php
    $fmt = fn (int $minor, string $cur = 'BDT') => \App\Modules\Platform\Support\Fmt::money(\App\Modules\Platform\DTOs\Money::of($minor, $cur));
    $at = fn ($d) => $d === null ? __('shipping::fulfilment.none') : \App\Modules\Platform\Support\Fmt::dateTime($d);
    $created = $parcel->status->value === 'created';
@endphp

@section('content')
<div class="space-y-8">
    <div><a class="text-sm underline" href="{{ route('admin.shipping.shipments.index') }}">{{ __('shipping::fulfilment.back') }}</a>
        <h1 class="mt-1 text-2xl font-bold">{{ __('shipping::fulfilment.show.title', ['id' => strtoupper(substr($parcel->public_id, -8))]) }}</h1></div>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    @error('parcel')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror

    <p><a class="underline" href="{{ route('admin.shipping.shipments.packing_slip', $parcel->public_id) }}">{{ __('shipping::fulfilment.documents.packing_slip_link') }}</a></p>

    <section aria-labelledby="d-h" class="space-y-2">
        <h2 id="d-h" class="text-lg font-semibold">{{ __('shipping::fulfilment.show.details') }}</h2>
        <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
            <div><dt class="text-muted">{{ __('shipping::fulfilment.show.order') }}</dt><dd>@if ($order)<a class="underline" href="{{ route('admin.shipping.orders.show', $order['public_id']) }}">{{ $order['number'] }}</a>@endif</dd></div>
            <div><dt class="text-muted">{{ __('shipping::fulfilment.shipments.status') }}</dt><dd>{{ __('shipping::fulfilment.status.'.$parcel->status->value) }}</dd></div>
            <div><dt class="text-muted">{{ __('shipping::fulfilment.show.courier') }}</dt><dd>{{ $parcel->courier }}</dd></div>
            <div><dt class="text-muted">{{ __('shipping::fulfilment.show.consignment') }}</dt><dd>{{ $parcel->courier_consignment_id ?? __('shipping::fulfilment.show.unbooked') }}</dd></div>
            <div><dt class="text-muted">{{ __('shipping::fulfilment.show.tracking') }}</dt><dd>{{ $parcel->tracking_code ?? __('shipping::fulfilment.none') }}</dd></div>
            <div><dt class="text-muted">{{ __('shipping::fulfilment.show.cash') }}</dt><dd>{{ $parcel->cod_amount_minor > 0 ? $fmt($parcel->cod_amount_minor, $parcel->currency) : __('shipping::fulfilment.none') }}</dd></div>
            <div><dt class="text-muted">{{ __('shipping::fulfilment.show.attempts') }}</dt><dd>{{ $parcel->attempts }}</dd></div>
            <div><dt class="text-muted">{{ __('shipping::fulfilment.show.handed_over_at') }}</dt><dd>{{ $at($parcel->handed_over_at) }}</dd></div>
            <div><dt class="text-muted">{{ __('shipping::fulfilment.show.delivered_at') }}</dt><dd>{{ $at($parcel->delivered_at) }}</dd></div>
        </dl>
    </section>

    <section aria-labelledby="i-h" class="space-y-2">
        <h2 id="i-h" class="text-lg font-semibold">{{ __('shipping::fulfilment.show.items') }}</h2>
        <ul class="text-sm">@foreach ($parcel->items as $i)<li>{{ $i->quantity }} × {{ $names[$i->order_item_id] ?? '#'.$i->order_item_id }}</li>@endforeach</ul>
    </section>

    @if ($can)
    <section aria-labelledby="a-h" class="space-y-4">
        <h2 id="a-h" class="text-lg font-semibold">{{ __('shipping::fulfilment.show.actions') }}</h2>
        @if ($created)
        <div class="flex flex-wrap gap-3">
            @if ($parcel->courier_consignment_id === null && $parcel->courier !== 'manual')
            <form method="POST" action="{{ route('admin.shipping.shipments.book', $parcel->public_id) }}">@csrf<x-platform::btn type="submit" size="sm">{{ __('shipping::fulfilment.show.book') }}</x-platform::btn></form>
            @endif
            <form method="POST" action="{{ route('admin.shipping.shipments.hand_over', $parcel->public_id) }}">@csrf<x-platform::btn type="submit" size="sm">{{ __('shipping::fulfilment.show.hand_over') }}</x-platform::btn></form>
        </div>
        <form method="POST" action="{{ route('admin.shipping.shipments.cancel', $parcel->public_id) }}" class="space-y-2 rounded-lg border border-line p-4">
            @csrf
            <h3 class="font-semibold">{{ __('shipping::fulfilment.show.cancel') }}</h3><p class="text-sm text-muted">{{ __('shipping::fulfilment.show.cancel_help') }}</p>
            <label for="reason" class="block text-sm font-medium">{{ __('shipping::fulfilment.show.reason') }}</label><input id="reason" name="reason" required maxlength="255" class="block w-full rounded-md border border-line px-3 py-2">
            <x-platform::btn type="submit" size="sm" variant="secondary">{{ __('shipping::fulfilment.show.cancel') }}</x-platform::btn>
        </form>
        @endif
        @if ($manualStatuses !== [] && ! $created && ! $parcel->status->isFinal())
        <form method="POST" action="{{ route('admin.shipping.shipments.status', $parcel->public_id) }}" class="space-y-2 rounded-lg border border-line p-4">
            @csrf
            <h3 class="font-semibold">{{ __('shipping::fulfilment.show.manual') }}</h3>
            <label for="status" class="block text-sm font-medium">{{ __('shipping::fulfilment.show.manual_status') }}</label>
            <select id="status" name="status" class="block w-full rounded-md border border-line px-3 py-2">@foreach ($manualStatuses as $s)<option value="{{ $s }}">{{ __('shipping::fulfilment.status.'.$s) }}</option>@endforeach</select>
            <label for="note" class="block text-sm font-medium">{{ __('shipping::fulfilment.show.note') }}</label><input id="note" name="note" maxlength="255" class="block w-full rounded-md border border-line px-3 py-2">
            <x-platform::btn type="submit" size="sm">{{ __('shipping::fulfilment.show.record') }}</x-platform::btn>
        </form>
        @endif
    </section>
    @endif

    <section aria-labelledby="h-h" class="space-y-2">
        <h2 id="h-h" class="text-lg font-semibold">{{ __('shipping::fulfilment.show.history') }}</h2>
        <div class="overflow-x-auto"><table class="w-full text-sm">
            <caption class="sr-only">{{ __('shipping::fulfilment.show.history') }}</caption>
            <thead><tr class="border-b border-line">@foreach (['when', 'from', 'courier_said', 'we_read', 'note'] as $col)<th scope="col" class="py-2 pe-3 text-start">{{ __('shipping::fulfilment.show.'.$col) }}</th>@endforeach</tr></thead>
            <tbody>
            @foreach ($parcel->events as $e)
                <tr class="border-b border-line"><td class="py-2 pe-3">{{ $at($e->occurred_at) }}</td><td class="py-2 pe-3">{{ __('shipping::fulfilment.show.source.'.$e->source) }}</td><td class="py-2 pe-3">{{ $e->provider_status }}</td>
                    <td class="py-2 pe-3">{{ __('shipping::fulfilment.status.'.$e->internal_status) }}</td><td class="py-2 pe-3">{{ $e->note }}</td></tr>
            @endforeach
            </tbody>
        </table></div>
    </section>
</div>
@endsection
