@extends('layouts.admin')

@section('title', __('payments::admin.today_title'))

@php
    $fmt = fn (int $minor) => \App\Modules\Platform\Support\Fmt::money(\App\Modules\Platform\DTOs\Money::of($minor, 'BDT'));
    $n = fn (int $v) => \App\Modules\Platform\Support\Fmt::number($v);
@endphp

@section('content')
<div class="space-y-8">
    <h1 class="text-2xl font-bold">{{ __('payments::admin.today_title') }}</h1>

    <dl class="grid gap-4 sm:grid-cols-3">
        @foreach ([['online_paid', $report['online_paid'], 'payments_count'], ['cod_collected_today', $report['cod_collected'], 'payments_count'], ['refunded_today', $report['refunded'], 'refunds_count']] as [$label, $row, $count])
            <div class="rounded-lg border border-line p-4">
                <dt class="text-sm text-muted">{{ __('payments::admin.'.$label) }}</dt>
                <dd class="text-2xl font-bold">{{ $fmt($row['minor']) }}</dd>
                <dd class="text-sm text-muted">{{ __('payments::admin.'.$count, ['count' => $n($row['count'])]) }}</dd>
            </div>
        @endforeach
    </dl>

    <dl class="grid gap-4 sm:grid-cols-3">
        <div class="rounded-lg border border-line p-4"><dt class="text-sm text-muted">{{ __('payments::admin.refunds_waiting') }}</dt><dd class="text-2xl font-bold"><a class="underline" href="{{ route('admin.payments.refunds.index') }}">{{ $n($report['refunds_waiting']) }}</a></dd></div>
        <div class="rounded-lg border border-line p-4"><dt class="text-sm text-muted">{{ __('payments::admin.refunds_with_gateway') }}</dt><dd class="text-2xl font-bold">{{ $n($report['refunds_with_gateway']) }}</dd></div>
        <div class="rounded-lg border border-line p-4"><dt class="text-sm text-muted">{{ __('payments::admin.needs_review') }}</dt><dd class="text-2xl font-bold"><a class="underline" href="{{ route('admin.payments.index', ['status' => 'needs_review']) }}">{{ $n($report['payments_needing_review']) }}</a></dd></div>
    </dl>

    <section aria-labelledby="open-h" class="space-y-2">
        <h2 id="open-h" class="text-lg font-semibold">{{ __('payments::admin.open_exceptions') }}</h2>
        @forelse ($report['open_exceptions'] as $type => $count)
            <p><a class="underline" href="{{ route('admin.payments.reconciliation.index', ['type' => $type]) }}">{{ __('payments::admin.kind.'.$type) }}</a>: <strong>{{ $n($count) }}</strong></p>
        @empty
            <p class="text-muted">{{ __('payments::admin.none_open') }}</p>
        @endforelse
    </section>

    <section aria-labelledby="aging-h" class="space-y-4">
        <h2 id="aging-h" class="text-lg font-semibold">{{ __('payments::admin.cod_aging') }}</h2>
        @include('payments::admin.cod._aging', ['aging' => $report['cod']])
    </section>
</div>
@endsection
