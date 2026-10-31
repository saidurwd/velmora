@extends('layouts.admin')

@section('title', __('payments::admin.cod_title'))

@php
    $fmt = fn (int $minor, string $cur = 'BDT') => \App\Modules\Platform\Support\Fmt::money(\App\Modules\Platform\DTOs\Money::of($minor, $cur));
    $dec = fn (int $minor, string $cur = 'BDT') => \App\Modules\Payments\Support\ProviderAmount::toDecimal(\App\Modules\Platform\DTOs\Money::of($minor, $cur));
@endphp

@section('content')
<div class="space-y-8">
    <h1 class="text-2xl font-bold">{{ __('payments::admin.cod_title') }}</h1>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    @error('collect')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror
    @error('remit')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror

    <section aria-labelledby="aging-h" class="space-y-3">
        <h2 id="aging-h" class="text-lg font-semibold">{{ __('payments::admin.cod_aging') }}</h2>
        @include('payments::admin.cod._aging', ['aging' => $aging])
    </section>

    @if ($can)
    <section aria-labelledby="collect-h">
        <form method="POST" action="{{ route('admin.payments.cod.collect') }}" class="space-y-3 rounded-lg border border-line p-4">
            @csrf
            <h2 id="collect-h" class="text-lg font-semibold">{{ __('payments::admin.collect_title') }}</h2>
            <p class="text-sm text-muted">{{ __('payments::admin.cod_collect_help') }}</p>
            <div class="grid gap-3 sm:grid-cols-3">
                <div><label for="order_number" class="block text-sm font-medium">{{ __('payments::admin.order_number') }}</label><input id="order_number" name="order_number" value="{{ old('order_number') }}" maxlength="20" required class="block w-full rounded-md border border-line px-3 py-2"></div>
                <div><label for="collected_on" class="block text-sm font-medium">{{ __('payments::admin.collected_on') }}</label><input id="collected_on" type="date" name="collected_on" value="{{ old('collected_on') }}" class="block w-full rounded-md border border-line px-3 py-2"></div>
                <div><label for="note" class="block text-sm font-medium">{{ __('payments::admin.note') }}</label><input id="note" name="note" value="{{ old('note') }}" maxlength="255" class="block w-full rounded-md border border-line px-3 py-2"></div>
            </div>
            <x-platform::btn type="submit" size="sm">{{ __('payments::admin.collect_btn') }}</x-platform::btn>
        </form>
    </section>
    @endif

    <section aria-labelledby="unrem-h" class="space-y-3">
        <h2 id="unrem-h" class="text-lg font-semibold">{{ __('payments::admin.unremitted_title') }}</h2>
        @if ($unremitted->isEmpty())
            <p class="text-muted">{{ __('payments::admin.unremitted_empty') }}</p>
        @else
        <form method="POST" action="{{ route('admin.payments.cod.remit') }}" class="space-y-3">
            @csrf
            <div class="overflow-x-auto"><table class="w-full text-sm">
                <caption class="sr-only">{{ __('payments::admin.unremitted_title') }}</caption>
                <thead><tr class="border-b border-line">
                    @if ($can)<th scope="col" class="py-2 pe-3 text-start">{{ __('payments::admin.include') }}</th>@endif
                    <th scope="col" class="py-2 pe-3 text-start">{{ __('payments::admin.cols.order') }}</th><th scope="col" class="py-2 pe-3 text-start">{{ __('payments::admin.collected_amount') }}</th>
                    <th scope="col" class="py-2 pe-3 text-start">{{ __('payments::admin.collected_at') }}</th>@if ($can)<th scope="col" class="py-2 pe-3 text-start">{{ __('payments::admin.courier_says') }}</th>@endif
                </tr></thead>
                <tbody>
                @foreach ($unremitted as $c)
                    <tr class="border-b border-line">
                        @if ($can)<td class="py-2 pe-3"><input type="checkbox" name="parcels[]" value="{{ $c->public_id }}" id="p-{{ $c->id }}" aria-label="{{ $orders[$c->order_id]['number'] ?? $c->public_id }}" @checked(in_array($c->public_id, (array) old('parcels', [])))></td>@endif
                        <td class="py-2 pe-3">@if (isset($orders[$c->order_id]))<a class="underline" href="{{ route('admin.orders.show', $orders[$c->order_id]['public_id']) }}">{{ $orders[$c->order_id]['number'] }}</a>@endif</td>
                        <td class="py-2 pe-3">{{ $fmt($c->amount_minor, $c->currency) }}</td><td class="py-2 pe-3">{{ \App\Modules\Platform\Support\Fmt::dateTime($c->collected_at) }}</td>
                        @if ($can)<td class="py-2 pe-3"><input name="amounts[{{ $c->public_id }}]" value="{{ old('amounts.'.$c->public_id, $dec($c->amount_minor, $c->currency)) }}" inputmode="decimal" aria-label="{{ __('payments::admin.courier_says') }} {{ $orders[$c->order_id]['number'] ?? '' }}" class="w-28 rounded-md border border-line px-2 py-1"></td>@endif
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            @if ($can)
            <div class="space-y-3 rounded-lg border border-line p-4">
                <h3 class="font-semibold">{{ __('payments::admin.remit_title') }}</h3>
                <div class="grid gap-3 sm:grid-cols-4">
                    <div><label for="courier" class="block text-sm font-medium">{{ __('payments::admin.courier') }}</label><input id="courier" name="courier" value="{{ old('courier') }}" maxlength="40" required class="block w-full rounded-md border border-line px-3 py-2"></div>
                    <div><label for="statement" class="block text-sm font-medium">{{ __('payments::admin.statement') }}</label><input id="statement" name="statement" value="{{ old('statement') }}" maxlength="80" required class="block w-full rounded-md border border-line px-3 py-2"></div>
                    <div><label for="received" class="block text-sm font-medium">{{ __('payments::admin.received') }}</label><input id="received" name="received" value="{{ old('received') }}" inputmode="decimal" required class="block w-full rounded-md border border-line px-3 py-2"></div>
                    <div><label for="received_on" class="block text-sm font-medium">{{ __('payments::admin.received_on') }}</label><input id="received_on" type="date" name="received_on" value="{{ old('received_on') }}" class="block w-full rounded-md border border-line px-3 py-2"></div>
                </div>
                <x-platform::btn type="submit" size="sm">{{ __('payments::admin.remit_btn') }}</x-platform::btn>
            </div>
            @endif
        </form>
        @endif
    </section>

    <section aria-labelledby="rem-h" class="space-y-2">
        <h2 id="rem-h" class="text-lg font-semibold">{{ __('payments::admin.remittances_title') }}</h2>
        <div class="overflow-x-auto"><table class="w-full text-sm">
            <caption class="sr-only">{{ __('payments::admin.remittances_title') }}</caption>
            <thead><tr class="border-b border-line">@foreach (['courier', 'statement', 'received', 'expected', 'status', 'date'] as $col)<th scope="col" class="py-2 pe-3 text-start">{{ __('payments::admin.remit_cols.'.$col) }}</th>@endforeach</tr></thead>
            <tbody>
            @foreach ($remittances as $r)
                <tr class="border-b border-line"><td class="py-2 pe-3">{{ $r->courier }}</td><td class="py-2 pe-3">{{ $r->statement_ref }}</td><td class="py-2 pe-3">{{ $fmt($r->amount_minor, $r->currency) }}</td><td class="py-2 pe-3">{{ $fmt($r->expected_minor, $r->currency) }}</td>
                    <td class="py-2 pe-3"><x-platform::badge :tone="$r->status === 'reconciled' ? 'success' : ($r->status === 'pending' ? 'warning' : 'danger')">{{ __('payments::admin.remit_status.'.$r->status) }}</x-platform::badge></td><td class="py-2 pe-3">{{ \App\Modules\Platform\Support\Fmt::dateTime($r->received_at) }}</td></tr>
            @endforeach
            </tbody>
        </table></div>
    </section>
</div>
@endsection
