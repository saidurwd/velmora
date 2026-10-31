@extends('layouts.account')

@section('title', __('orders::account.title'))

@php
    $fmt = fn (int $minor, string $cur) => \App\Modules\Platform\Support\Fmt::money(\App\Modules\Platform\DTOs\Money::of($minor, $cur));
@endphp

@section('account')
<h1 class="text-2xl font-bold">{{ __('orders::account.title') }}</h1>

@if ($orders->isEmpty())
    <p class="text-muted">{{ __('orders::account.empty') }}</p>
@else
<div class="overflow-x-auto">
<table class="w-full text-sm">
    <caption class="sr-only">{{ __('orders::account.title') }}</caption>
    <thead><tr class="border-b border-line">
        @foreach (['number', 'date', 'total', 'status', 'payment'] as $col)<th scope="col" class="py-2 pe-3 text-start">{{ __('orders::account.cols.'.$col) }}</th>@endforeach
        <th scope="col" class="py-2 text-start"><span class="sr-only">{{ __('orders::account.view') }}</span></th>
    </tr></thead>
    <tbody>
    @foreach ($orders as $order)
        <tr class="border-b border-line">
            <th scope="row" class="py-2 pe-3 text-start font-medium">{{ $order->order_number }}</th>
            <td class="py-2 pe-3">{{ \App\Modules\Platform\Support\Fmt::dateTime($order->placed_at) }}</td>
            <td class="py-2 pe-3">{{ $fmt($order->grand_total_minor, $order->currency) }}</td>
            <td class="py-2 pe-3">{{ __('orders::orders.status.'.$order->status->value) }}</td>
            <td class="py-2 pe-3">{{ __('orders::orders.payment.'.$order->payment_status->value) }}</td>
            <td class="py-2"><a class="underline" href="{{ route('account.orders.show', $order->public_id) }}">{{ __('orders::account.view') }}<span class="sr-only"> {{ $order->order_number }}</span></a></td>
        </tr>
    @endforeach
    </tbody>
</table>
</div>
{{ $orders->links() }}
@endif
@endsection
