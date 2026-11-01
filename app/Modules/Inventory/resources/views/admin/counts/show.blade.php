@extends('layouts.admin')

@section('title', __('inventory::screens.counts.show_title', ['id' => strtoupper(substr($count->public_id, -8))]))

@php $status = $count->status->value; @endphp

@section('content')
<div class="space-y-6">
    <div><a class="text-sm underline" href="{{ route('admin.inventory.counts.index') }}">{{ __('inventory::screens.back') }}</a>
        <h1 class="mt-1 text-2xl font-bold">{{ __('inventory::screens.counts.show_title', ['id' => strtoupper(substr($count->public_id, -8))]) }}</h1>
        <p class="text-muted">{{ $place }} · {{ __('inventory::screens.counts.status.'.$status) }}@if ($count->note) · {{ $count->note }}@endif @if ($count->decision_note) · {{ $count->decision_note }}@endif</p></div>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    @error('count')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror

    @if ($status === 'open' && $canCount)<form method="POST" action="{{ route('admin.inventory.counts.record', $count->public_id) }}" class="space-y-3">@csrf @endif
    <div class="overflow-x-auto"><table class="w-full text-sm">
        <caption class="sr-only">{{ __('inventory::screens.counts.lines') }}</caption>
        <thead><tr class="border-b border-line">@foreach (['sku', 'product', 'expected', 'counted', 'variance'] as $col)<th scope="col" class="py-2 pe-3 text-start">{{ __('inventory::screens.counts.cols.'.$col) }}</th>@endforeach</tr></thead>
        <tbody>
        @foreach ($count->lines as $l)
            <tr class="border-b border-line"><td class="py-2 pe-3">{{ $variants[$l->variant_id]->sku ?? '#'.$l->variant_id }}</td><td class="py-2 pe-3">{{ $variants[$l->variant_id]->productName ?? '' }}</td><td class="py-2 pe-3">{{ $l->expected_qty }}</td>
                <td class="py-2 pe-3">@if ($status === 'open' && $canCount)<input type="number" min="0" name="counted[{{ $l->variant_id }}]" value="{{ $l->counted_qty }}" aria-label="{{ __('inventory::screens.counts.cols.counted') }}: {{ $variants[$l->variant_id]->sku ?? '' }}" class="w-24 rounded-md border border-line px-2 py-1">@else{{ $l->counted_qty ?? __('inventory::screens.none') }}@endif</td>
                <td class="py-2 pe-3">{{ $l->variance === null ? __('inventory::screens.none') : ($l->variance > 0 ? '+'.$l->variance : $l->variance) }}</td></tr>
        @endforeach
        </tbody>
    </table></div>
    @if ($status === 'open' && $canCount)
        <x-platform::btn type="submit" size="sm">{{ __('inventory::screens.counts.save') }}</x-platform::btn>
    </form>
    <div class="flex flex-wrap gap-3">
        <form method="POST" action="{{ route('admin.inventory.counts.submit', $count->public_id) }}">@csrf<x-platform::btn type="submit" size="sm">{{ __('inventory::screens.counts.submit') }}</x-platform::btn></form>
        <form method="POST" action="{{ route('admin.inventory.counts.cancel', $count->public_id) }}">@csrf<x-platform::btn type="submit" size="sm" variant="secondary">{{ __('inventory::screens.counts.cancel') }}</x-platform::btn></form>
    </div>
    @endif

    @if ($status === 'submitted')
        @if ($canDecide)
        <div class="grid gap-4 sm:grid-cols-2">
            <form method="POST" action="{{ route('admin.inventory.counts.approve', $count->public_id) }}" class="space-y-2 rounded-lg border border-line p-4">@csrf
                <h2 class="font-semibold">{{ __('inventory::screens.counts.approve') }}</h2><p class="text-sm text-muted">{{ __('inventory::screens.counts.approve_help') }}</p>
                <x-platform::btn type="submit" size="sm">{{ __('inventory::screens.counts.approve') }}</x-platform::btn></form>
            <form method="POST" action="{{ route('admin.inventory.counts.reject', $count->public_id) }}" class="space-y-2 rounded-lg border border-line p-4">@csrf
                <h2 class="font-semibold">{{ __('inventory::screens.counts.reject') }}</h2>
                <label for="reason" class="block text-sm font-medium">{{ __('inventory::screens.reason') }}</label><input id="reason" name="reason" required maxlength="255" class="block w-full rounded-md border border-line px-3 py-2">
                <x-platform::btn type="submit" size="sm" variant="secondary">{{ __('inventory::screens.counts.reject') }}</x-platform::btn></form>
        </div>
        @else
        <p class="text-sm text-muted">{{ __('inventory::screens.counts.waiting') }}</p>
        @endif
    @endif
</div>
@endsection
