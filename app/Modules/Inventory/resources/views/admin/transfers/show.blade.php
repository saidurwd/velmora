@extends('layouts.admin')

@section('title', __('inventory::screens.transfers.show_title', ['id' => strtoupper(substr($transfer->public_id, -8))]))

@php $open = $transfer->status->value === 'in_transit'; @endphp

@section('content')
<div class="space-y-6">
    <div><a class="text-sm underline" href="{{ route('admin.inventory.transfers.index') }}">{{ __('inventory::screens.back') }}</a>
        <h1 class="mt-1 text-2xl font-bold">{{ __('inventory::screens.transfers.show_title', ['id' => strtoupper(substr($transfer->public_id, -8))]) }}</h1>
        <p class="text-muted">{{ $places[$transfer->from_location_id] ?? '' }} → {{ $places[$transfer->to_location_id] ?? '' }} · {{ __('inventory::screens.transfers.status.'.$transfer->status->value) }}@if ($transfer->note) · {{ $transfer->note }}@endif</p></div>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    @error('transfer')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror

    @if ($open && $can)<form method="POST" action="{{ route('admin.inventory.transfers.receive', $transfer->public_id) }}" class="space-y-3">@csrf @endif
    <div class="overflow-x-auto"><table class="w-full text-sm">
        <caption class="sr-only">{{ __('inventory::screens.transfers.items') }}</caption>
        <thead><tr class="border-b border-line"><th scope="col" class="py-2 pe-3 text-start">{{ __('inventory::screens.sku') }}</th><th scope="col" class="py-2 pe-3 text-start">{{ __('inventory::screens.product') }}</th><th scope="col" class="py-2 pe-3 text-start">{{ __('inventory::screens.transfers.sent') }}</th><th scope="col" class="py-2 pe-3 text-start">{{ __('inventory::screens.transfers.arrived') }}</th><th scope="col" class="py-2 pe-3 text-start">{{ __('inventory::screens.transfers.lost') }}</th></tr></thead>
        <tbody>
        @foreach ($transfer->items as $i)
            <tr class="border-b border-line"><td class="py-2 pe-3">{{ $variants[$i->variant_id]->sku ?? '#'.$i->variant_id }}</td><td class="py-2 pe-3">{{ $variants[$i->variant_id]->productName ?? '' }}</td><td class="py-2 pe-3">{{ $i->sent_qty }}</td>
                <td class="py-2 pe-3">@if ($open && $can)<input type="number" min="0" max="{{ $i->sent_qty }}" name="received[{{ $i->variant_id }}]" aria-label="{{ __('inventory::screens.transfers.arrived') }}: {{ $variants[$i->variant_id]->sku ?? '' }}" class="w-24 rounded-md border border-line px-2 py-1">@else{{ $i->received_qty ?? __('inventory::screens.none') }}@endif</td>
                <td class="py-2 pe-3">{{ $i->lost_qty ?? __('inventory::screens.none') }}</td></tr>
        @endforeach
        </tbody>
    </table></div>
    @if ($open && $can)
        <p class="text-sm text-muted">{{ __('inventory::screens.transfers.receive_help') }}</p>
        <x-platform::btn type="submit" size="sm">{{ __('inventory::screens.transfers.receive') }}</x-platform::btn>
    </form>
    <form method="POST" action="{{ route('admin.inventory.transfers.cancel', $transfer->public_id) }}" class="space-y-2 rounded-lg border border-line p-4">@csrf
        <h2 class="font-semibold">{{ __('inventory::screens.transfers.cancel') }}</h2><p class="text-sm text-muted">{{ __('inventory::screens.transfers.cancel_help') }}</p>
        <label for="reason" class="block text-sm font-medium">{{ __('inventory::screens.reason') }}</label><input id="reason" name="reason" required maxlength="255" class="block w-full rounded-md border border-line px-3 py-2">
        <x-platform::btn type="submit" size="sm" variant="secondary">{{ __('inventory::screens.transfers.cancel') }}</x-platform::btn>
    </form>
    @endif
</div>
@endsection
