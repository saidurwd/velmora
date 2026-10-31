@extends('layouts.admin')

@section('title', __('payments::admin.queue_title'))

@php
    $fmt = fn (int $minor, string $cur) => \App\Modules\Platform\Support\Fmt::money(\App\Modules\Platform\DTOs\Money::of($minor, $cur));
@endphp

@section('content')
<div class="space-y-6">
    <h1 class="text-2xl font-bold">{{ __('payments::admin.queue_title') }}</h1>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    @error('refund')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror

    @forelse ($refunds as $refund)
        @php $payment = $payments[$refund->payment_id] ?? null; $order = $orders[$refund->order_id] ?? null; @endphp
        <article class="space-y-3 rounded-lg border border-line p-4" aria-label="{{ $order['number'] ?? '' }}">
            <header class="flex flex-wrap items-baseline gap-3">
                <strong>{{ $fmt($refund->amount_minor, $refund->currency) }}</strong>
                <span>@if ($order)<a class="underline" href="{{ route('admin.orders.show', $order['public_id']) }}">{{ $order['number'] }}</a>@endif @if ($payment)· <a class="underline" href="{{ route('admin.payments.show', $payment->public_id) }}">{{ strtoupper(substr($payment->public_id, -8)) }}</a>@endif</span>
                <x-platform::badge tone="warning">{{ __('payments::admin.refund_status.'.$refund->status->value) }}</x-platform::badge>
                <span class="text-sm text-muted">{{ __('payments::admin.refund_method.'.$refund->method->value) }}</span>
            </header>
            <p class="text-sm">{{ $refund->reason }}</p>
            <p class="text-sm text-muted">{{ __('payments::admin.requested_by') }}: {{ $staff[$refund->requested_by] ?? __('payments::admin.unknown') }}@if ($refund->approved_by) · {{ __('payments::admin.approved_by') }}: {{ $staff[$refund->approved_by] ?? __('payments::admin.unknown') }}@endif</p>

            @if ($can)
            <div class="flex flex-wrap items-end gap-4">
                @if ($refund->status === \App\Modules\Payments\Enums\RefundStatus::Requested)
                    <form method="POST" action="{{ route('admin.payments.refunds.approve', $refund->public_id) }}">@csrf<x-platform::btn type="submit" size="sm">{{ __('payments::admin.approve') }}</x-platform::btn></form>
                    <form method="POST" action="{{ route('admin.payments.refunds.reject', $refund->public_id) }}" class="flex items-end gap-2">@csrf
                        <div><label for="rej-{{ $refund->id }}" class="block text-sm">{{ __('payments::admin.reject_reason') }}</label><input id="rej-{{ $refund->id }}" name="reason" maxlength="255" required class="rounded-md border border-line px-3 py-1"></div>
                        <x-platform::btn type="submit" size="sm" variant="secondary">{{ __('payments::admin.reject') }}</x-platform::btn></form>
                @elseif ($refund->status === \App\Modules\Payments\Enums\RefundStatus::Approved && $refund->method === \App\Modules\Payments\Enums\RefundMethod::Gateway)
                    <form method="POST" action="{{ route('admin.payments.refunds.send', $refund->public_id) }}">@csrf<x-platform::btn type="submit" size="sm">{{ __('payments::admin.send') }}</x-platform::btn></form>
                @elseif ($refund->status === \App\Modules\Payments\Enums\RefundStatus::Approved)
                    <form method="POST" action="{{ route('admin.payments.refunds.complete', $refund->public_id) }}" class="flex items-end gap-2">@csrf
                        <div><label for="proof-{{ $refund->id }}" class="block text-sm">{{ __('payments::admin.proof_label') }}</label><input id="proof-{{ $refund->id }}" name="proof" maxlength="120" required class="rounded-md border border-line px-3 py-1"></div>
                        <x-platform::btn type="submit" size="sm">{{ __('payments::admin.complete') }}</x-platform::btn></form>
                @endif
            </div>
            @endif
        </article>
    @empty
        <p class="text-muted">{{ __('payments::admin.refunds_empty') }}</p>
    @endforelse
    {{ $refunds->links() }}
</div>
@endsection
