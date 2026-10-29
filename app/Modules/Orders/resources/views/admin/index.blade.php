@extends('layouts.admin')

@section('title', __('orders::orders.title'))

@php
    $fmt = fn (int $minor, string $cur) => \App\Modules\Platform\Support\Fmt::money(\App\Modules\Platform\DTOs\Money::of($minor, $cur));
    $phone = fn (string $p) => $canSeePhone ? $p : (\App\Modules\Platform\DTOs\PhoneNumber::tryParse($p)?->masked() ?? '—');
    $tone = fn (string $axis, string $v) => match (true) {
        in_array($v, ['cancelled', 'failed', 'returned'], true) => 'danger',
        in_array($v, ['completed', 'paid', 'fulfilled'], true) => 'success',
        in_array($v, ['pending', 'unpaid', 'unfulfilled'], true) => 'warning',
        default => 'brand',
    };
@endphp

@section('content')
<div class="space-y-6">
    <h1 class="text-2xl font-bold">{{ __('orders::orders.title') }}</h1>

    <form method="GET" role="search" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <div class="lg:col-span-2"><label for="q" class="block text-sm font-medium">{{ __('orders::orders.search') }}</label>
            <input id="q" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="60" class="block w-full rounded-md border border-line px-3 py-2"></div>
        @foreach (['status' => \App\Modules\Orders\Enums\OrderStatus::class, 'payment' => \App\Modules\Orders\Enums\PaymentStatus::class, 'fulfillment' => \App\Modules\Orders\Enums\FulfillmentStatus::class] as $key => $enum)
            <div><label for="f-{{ $key }}" class="block text-sm font-medium">{{ __('orders::orders.cols.'.$key) }}</label>
                <select id="f-{{ $key }}" name="{{ $key }}" class="block w-full rounded-md border border-line px-3 py-2">
                    <option value="">{{ __('orders::orders.all') }}</option>
                    @foreach ($enum::cases() as $case)<option value="{{ $case->value }}" @selected(($filters[$key] ?? '') === $case->value)>{{ __('orders::orders.'.$key.'.'.$case->value) }}</option>@endforeach
                </select></div>
        @endforeach
        <div class="flex items-end gap-4 sm:col-span-2 lg:col-span-5">
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="review" value="1" @checked(! empty($filters['review']))> {{ __('orders::orders.only_review') }}</label>
            <button class="min-h-11 rounded-md border border-line px-5 py-2">{{ __('orders::orders.filter') }}</button>
        </div>
    </form>

    <div class="overflow-x-auto">
    <table class="w-full text-sm">
        <caption class="sr-only">{{ __('orders::orders.title') }}</caption>
        <thead><tr class="border-b border-line">
            @foreach (['number', 'placed', 'customer', 'total', 'status', 'payment', 'fulfillment'] as $col)<th scope="col" class="py-2 pe-3 text-start">{{ __('orders::orders.cols.'.$col) }}</th>@endforeach
        </tr></thead>
        <tbody>
        @forelse ($orders as $order)
            <tr class="border-b border-line">
                <td class="py-2 pe-3"><a class="underline" href="{{ route('admin.orders.show', $order) }}">{{ $order->order_number }}</a>@if ($order->needs_review) <x-platform::badge tone="danger">{{ __('orders::orders.needs_review') }}</x-platform::badge>@endif</td>
                <td class="py-2 pe-3">{{ \App\Modules\Platform\Support\Fmt::dateTime($order->placed_at) }}</td>
                <td class="py-2 pe-3">{{ $order->contact_name }}<br><span class="text-muted">{{ $phone($order->contact_phone) }}</span></td>
                <td class="py-2 pe-3">{{ $fmt($order->grand_total_minor, $order->currency) }}</td>
                <td class="py-2 pe-3"><x-platform::badge :tone="$tone('status', $order->status->value)">{{ __('orders::orders.status.'.$order->status->value) }}</x-platform::badge></td>
                <td class="py-2 pe-3"><x-platform::badge :tone="$tone('payment', $order->payment_status->value)">{{ __('orders::orders.payment.'.$order->payment_status->value) }}</x-platform::badge></td>
                <td class="py-2 pe-3"><x-platform::badge :tone="$tone('fulfillment', $order->fulfillment_status->value)">{{ __('orders::orders.fulfillment.'.$order->fulfillment_status->value) }}</x-platform::badge></td>
            </tr>
        @empty
            <tr><td colspan="7" class="py-6 text-center text-muted">{{ __('orders::orders.empty') }}</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
    {{ $orders->links() }}
</div>
@endsection
