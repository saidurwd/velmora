@extends('layouts.admin')

@section('title', __('payments::admin.payments_title'))

@php
    $fmt = fn (int $minor, string $cur) => \App\Modules\Platform\Support\Fmt::money(\App\Modules\Platform\DTOs\Money::of($minor, $cur));
    $tone = fn (string $v) => match ($v) { 'failed', 'cancelled', 'expired' => 'danger', 'paid' => 'success', 'needs_review' => 'danger', default => 'warning' };
@endphp

@section('content')
<div class="space-y-6">
    <h1 class="text-2xl font-bold">{{ __('payments::admin.payments_title') }}</h1>

    <form method="GET" role="search" class="grid gap-3 sm:grid-cols-3">
        <div class="sm:col-span-2"><label for="q" class="block text-sm font-medium">{{ __('payments::admin.search') }}</label>
            <input id="q" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="80" class="block w-full rounded-md border border-line px-3 py-2"></div>
        <div><label for="status" class="block text-sm font-medium">{{ __('payments::admin.status') }}</label>
            <select id="status" name="status" class="block w-full rounded-md border border-line px-3 py-2">
                <option value="">{{ __('payments::admin.all') }}</option>
                @foreach (\App\Modules\Payments\Enums\PaymentState::cases() as $case)<option value="{{ $case->value }}" @selected(($filters['status'] ?? '') === $case->value)>{{ __('payments::admin.state.'.$case->value) }}</option>@endforeach
            </select></div>
        <div class="sm:col-span-3"><button class="min-h-11 rounded-md border border-line px-5 py-2">{{ __('payments::admin.filter') }}</button></div>
    </form>

    <div class="overflow-x-auto">
    <table class="w-full text-sm">
        <caption class="sr-only">{{ __('payments::admin.payments_title') }}</caption>
        <thead><tr class="border-b border-line">
            @foreach (['payment', 'order', 'method', 'amount', 'received', 'refunded', 'status', 'created'] as $col)<th scope="col" class="py-2 pe-3 text-start">{{ __('payments::admin.cols.'.$col) }}</th>@endforeach
        </tr></thead>
        <tbody>
        @forelse ($payments as $payment)
            <tr class="border-b border-line">
                <td class="py-2 pe-3"><a class="underline" href="{{ route('admin.payments.show', $payment->public_id) }}">{{ strtoupper(substr($payment->public_id, -8)) }}</a></td>
                <td class="py-2 pe-3">@if (isset($orders[$payment->order_id]))<a class="underline" href="{{ route('admin.orders.show', $orders[$payment->order_id]['public_id']) }}">{{ $orders[$payment->order_id]['number'] }}</a>@else — @endif</td>
                <td class="py-2 pe-3">{{ $payment->method_code }}</td>
                <td class="py-2 pe-3">{{ $fmt($payment->amount_minor, $payment->currency) }}</td>
                <td class="py-2 pe-3">{{ $fmt($payment->captured_minor, $payment->currency) }}</td>
                <td class="py-2 pe-3">{{ $fmt($payment->refunded_minor, $payment->currency) }}</td>
                <td class="py-2 pe-3"><x-platform::badge :tone="$tone($payment->status->value)">{{ __('payments::admin.state.'.$payment->status->value) }}</x-platform::badge></td>
                <td class="py-2 pe-3">{{ \App\Modules\Platform\Support\Fmt::dateTime($payment->created_at) }}</td>
            </tr>
        @empty
            <tr><td colspan="8" class="py-6 text-center text-muted">{{ __('payments::admin.empty') }}</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
    {{ $payments->links() }}
</div>
@endsection
