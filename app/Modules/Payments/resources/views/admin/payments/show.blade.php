@extends('layouts.admin')

@section('title', __('payments::admin.payments_title').' '.strtoupper(substr($payment->public_id, -8)))

@php
    $fmt = fn (int $minor) => \App\Modules\Platform\Support\Fmt::money(\App\Modules\Platform\DTOs\Money::of($minor, $payment->currency));
    $tone = fn (string $v) => match ($v) { 'failed', 'cancelled', 'expired', 'rejected', 'needs_review' => 'danger', 'paid', 'succeeded' => 'success', default => 'warning' };
    $max = \App\Modules\Platform\Support\Fmt::money(\App\Modules\Platform\DTOs\Money::of($payment->refundableMinor(), $payment->currency));
    $paidAttempts = $payment->transactions->where('status', \App\Modules\Payments\Enums\TransactionStatus::Succeeded);
@endphp

@section('content')
<div class="space-y-8">
    <p><a class="underline" href="{{ route('admin.payments.index') }}">{{ __('payments::admin.back_to_list') }}</a></p>
    <h1 class="text-2xl font-bold">{{ __('payments::admin.payments_title') }} {{ strtoupper(substr($payment->public_id, -8)) }}
        <x-platform::badge :tone="$tone($payment->status->value)">{{ __('payments::admin.state.'.$payment->status->value) }}</x-platform::badge></h1>

    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    @error('verify')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror
    @error('refund')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror

    <dl class="grid gap-4 sm:grid-cols-4">
        <div><dt class="text-sm text-muted">{{ __('payments::admin.cols.order') }}</dt><dd>@if ($order)<a class="underline" href="{{ route('admin.orders.show', $order['public_id']) }}">{{ $order['number'] }}</a>@else — @endif</dd></div>
        <div><dt class="text-sm text-muted">{{ __('payments::admin.cols.amount') }}</dt><dd>{{ $fmt($payment->amount_minor) }}</dd></div>
        <div><dt class="text-sm text-muted">{{ __('payments::admin.cols.received') }}</dt><dd>{{ $fmt($payment->captured_minor) }}</dd></div>
        <div><dt class="text-sm text-muted">{{ __('payments::admin.cols.refunded') }}</dt><dd>{{ $fmt($payment->refunded_minor) }}</dd></div>
    </dl>

    @if ($can['reconcile'] && $payment->provider !== 'cod')
        <form method="POST" action="{{ route('admin.payments.verify', $payment->public_id) }}" class="space-y-1">
            @csrf
            <x-platform::btn type="submit" variant="secondary">{{ __('payments::admin.verify_btn') }}</x-platform::btn>
            <p class="text-sm text-muted">{{ __('payments::admin.verify_help') }}</p>
        </form>
    @endif

    <section aria-labelledby="att-h" class="space-y-2">
        <h2 id="att-h" class="text-lg font-semibold">{{ __('payments::admin.attempts') }}</h2>
        <div class="overflow-x-auto"><table class="w-full text-sm">
            <caption class="sr-only">{{ __('payments::admin.attempts') }}</caption>
            <thead><tr class="border-b border-line">@foreach (['attempt', 'reference', 'bank_id', 'status', 'amount', 'verified'] as $col)<th scope="col" class="py-2 pe-3 text-start">{{ __('payments::admin.attempt_cols.'.$col) }}</th>@endforeach</tr></thead>
            <tbody>
            @foreach ($payment->transactions as $tx)
                <tr class="border-b border-line">
                    <td class="py-2 pe-3">{{ $tx->attempt }}</td><td class="py-2 pe-3">{{ $tx->merchant_ref }}</td><td class="py-2 pe-3">{{ $tx->provider_txn_id ?? '—' }}</td>
                    <td class="py-2 pe-3"><x-platform::badge :tone="$tone($tx->status->value)">{{ __('payments::admin.attempt_state.'.$tx->status->value) }}</x-platform::badge></td>
                    <td class="py-2 pe-3">{{ $fmt($tx->amount_minor) }}</td><td class="py-2 pe-3">{{ $tx->verified_at ? \App\Modules\Platform\Support\Fmt::dateTime($tx->verified_at) : '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    </section>

    <section aria-labelledby="ref-h" class="space-y-2">
        <h2 id="ref-h" class="text-lg font-semibold">{{ __('payments::admin.refunds_title') }}</h2>
        @if ($refunds->isEmpty())<p class="text-muted">{{ __('payments::admin.refunds_empty') }}</p>@else
        <div class="overflow-x-auto"><table class="w-full text-sm">
            <caption class="sr-only">{{ __('payments::admin.refunds_title') }}</caption>
            <thead><tr class="border-b border-line">@foreach (['amount', 'status', 'method', 'reason', 'by', 'proof'] as $col)<th scope="col" class="py-2 pe-3 text-start">{{ __('payments::admin.refund_cols.'.$col) }}</th>@endforeach</tr></thead>
            <tbody>
            @foreach ($refunds as $r)
                <tr class="border-b border-line">
                    <td class="py-2 pe-3">{{ $fmt($r->amount_minor) }}</td>
                    <td class="py-2 pe-3"><x-platform::badge :tone="$tone($r->status->value)">{{ __('payments::admin.refund_status.'.$r->status->value) }}</x-platform::badge></td>
                    <td class="py-2 pe-3">{{ __('payments::admin.refund_method.'.$r->method->value) }}</td><td class="py-2 pe-3">{{ $r->reason }}</td>
                    <td class="py-2 pe-3">{{ $staff[$r->requested_by] ?? __('payments::admin.unknown') }} / {{ $staff[$r->approved_by] ?? __('payments::admin.unknown') }}</td>
                    <td class="py-2 pe-3">{{ $r->proof_ref ?? '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        @endif

        @if ($can['refund'] && $payment->refundableMinor() > 0)
        <form method="POST" action="{{ route('admin.payments.refunds.request', $payment->public_id) }}" class="space-y-3 rounded-lg border border-line p-4">
            @csrf
            <h3 class="font-semibold">{{ __('payments::admin.refund_request_title') }}</h3>
            <div><label for="amount" class="block text-sm font-medium">{{ __('payments::admin.amount_label') }}</label>
                <input id="amount" name="amount" value="{{ old('amount') }}" inputmode="decimal" required class="block w-full rounded-md border border-line px-3 py-2" aria-describedby="amount-help">
                <p id="amount-help" class="text-sm text-muted">{{ __('payments::admin.amount_help', ['max' => $max]) }}</p>
                @error('amount')<p class="text-sm text-danger">{{ $message }}</p>@enderror</div>
            <div><label for="reason" class="block text-sm font-medium">{{ __('payments::admin.reason_label') }}</label>
                <input id="reason" name="reason" value="{{ old('reason') }}" maxlength="255" required class="block w-full rounded-md border border-line px-3 py-2">
                @error('reason')<p class="text-sm text-danger">{{ $message }}</p>@enderror</div>
            @if ($paidAttempts->count() > 1)
            <div><label for="attempt" class="block text-sm font-medium">{{ __('payments::admin.attempt_label') }}</label>
                <select id="attempt" name="attempt" class="block w-full rounded-md border border-line px-3 py-2">
                    <option value="">{{ __('payments::admin.attempt_default') }}</option>
                    @foreach ($paidAttempts->skip(1) as $tx)<option value="{{ $tx->public_id }}">{{ $tx->merchant_ref }} ({{ $fmt($tx->amount_minor) }})</option>@endforeach
                </select></div>
            @endif
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="manual" value="1" @checked(old('manual'))> {{ __('payments::admin.manual_label') }}</label>
            <x-platform::btn type="submit" size="sm">{{ __('payments::admin.refund_request_btn') }}</x-platform::btn>
        </form>
        @endif
    </section>

    @if ($exceptions->isNotEmpty())
    <section aria-labelledby="exc-h" class="space-y-2">
        <h2 id="exc-h" class="text-lg font-semibold">{{ __('payments::admin.exceptions_title') }}</h2>
        <ul class="list-disc ps-5 text-sm">
            @foreach ($exceptions as $e)<li><a class="underline" href="{{ route('admin.payments.reconciliation.index', ['status' => 'all', 'type' => $e->type]) }}">{{ __('payments::admin.kind.'.$e->type) }}</a> — {{ __('payments::admin.exception_status.'.$e->status) }}</li>@endforeach
        </ul>
    </section>
    @endif

    <section aria-labelledby="ev-h" class="space-y-2">
        <h2 id="ev-h" class="text-lg font-semibold">{{ __('payments::admin.events_title') }}</h2>
        <p class="text-sm text-muted">{{ __('payments::admin.raw_note') }}</p>
        @forelse ($events as $row)
            <details class="rounded-md border border-line p-3 text-sm">
                <summary>{{ \App\Modules\Platform\Support\Fmt::dateTime($row['event']->received_at) }} · {{ $row['event']->status }} · {{ $row['event']->verified === null ? __('payments::admin.event_unchecked') : ($row['event']->verified ? __('payments::admin.event_checked') : __('payments::admin.no')) }}</summary>
                <pre dir="ltr" class="mt-2 overflow-x-auto whitespace-pre-wrap text-xs">{{ json_encode($row['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            </details>
        @empty
            <p class="text-muted">{{ __('payments::admin.events_empty') }}</p>
        @endforelse
    </section>
</div>
@endsection
