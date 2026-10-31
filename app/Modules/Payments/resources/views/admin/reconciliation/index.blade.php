@extends('layouts.admin')

@section('title', __('payments::admin.recon_title'))

@php
    $types = ['unmatched_provider_txn', 'amount_mismatch', 'currency_mismatch', 'orphan_payment', 'long_pending', 'late_success', 'duplicate_payment', 'cod_short', 'cod_over', 'refund_stuck'];
    $tone = fn (string $s) => $s === 'open' ? 'danger' : 'success';
    $summary = function (array $d): string {
        $skip = ['provider', 'line'];
        $parts = [];
        foreach ($d as $k => $v) { if (! in_array($k, $skip, true) && is_scalar($v)) { $parts[] = $k.': '.$v; } }
        return implode(' · ', array_slice($parts, 0, 6));
    };
@endphp

@section('content')
<div class="space-y-8">
    <h1 class="text-2xl font-bold">{{ __('payments::admin.recon_title') }}</h1>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    @error('import')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror
    @error('resolve')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror

    <form method="GET" role="search" class="grid gap-3 sm:grid-cols-3">
        <div><label for="f-status" class="block text-sm font-medium">{{ __('payments::admin.status') }}</label>
            <select id="f-status" name="status" class="block w-full rounded-md border border-line px-3 py-2">
                @foreach (['open', 'resolved', 'ignored', 'all'] as $s)<option value="{{ $s }}" @selected($filters['status'] === $s)>{{ $s === 'all' ? __('payments::admin.all') : __('payments::admin.exception_status.'.$s) }}</option>@endforeach
            </select></div>
        <div><label for="f-type" class="block text-sm font-medium">{{ __('payments::admin.type') }}</label>
            <select id="f-type" name="type" class="block w-full rounded-md border border-line px-3 py-2">
                <option value="">{{ __('payments::admin.all') }}</option>
                @foreach ($types as $t)<option value="{{ $t }}" @selected($filters['type'] === $t)>{{ __('payments::admin.kind.'.$t) }}</option>@endforeach
            </select></div>
        <div class="flex items-end"><button class="min-h-11 rounded-md border border-line px-5 py-2">{{ __('payments::admin.filter') }}</button></div>
    </form>

    <section class="space-y-3" aria-label="{{ __('payments::admin.recon_title') }}">
        @forelse ($exceptions as $e)
            <article class="space-y-2 rounded-lg border border-line p-4">
                <header class="flex flex-wrap items-baseline gap-3">
                    <strong>{{ in_array($e->type, $types, true) ? __('payments::admin.kind.'.$e->type) : $e->type }}</strong>
                    <x-platform::badge :tone="$tone($e->status)">{{ __('payments::admin.exception_status.'.$e->status) }}</x-platform::badge>
                    <span class="text-sm text-muted">{{ \App\Modules\Platform\Support\Fmt::dateTime($e->created_at) }}</span>
                </header>
                <p class="text-sm"><span class="text-muted">{{ __('payments::admin.exception_cols.reference') }}:</span> <span dir="ltr">{{ $e->reference }}</span>@if ($e->order_id && isset($orders[$e->order_id])) · <a class="underline" href="{{ route('admin.orders.show', $orders[$e->order_id]['public_id']) }}">{{ $orders[$e->order_id]['number'] }}</a>@endif</p>
                <p class="text-sm text-muted" dir="ltr">{{ $summary($e->details) }}</p>
                @if ($e->status !== 'open')
                    <p class="text-sm"><span class="text-muted">{{ __('payments::admin.closed_by_note') }}:</span> {{ $e->resolution }}</p>
                @elseif ($can)
                    <form method="POST" action="{{ route('admin.payments.reconciliation.resolve', $e->public_id) }}" class="flex flex-wrap items-end gap-3">
                        @csrf
                        <div><label for="o-{{ $e->id }}" class="block text-sm">{{ __('payments::admin.outcome_label') }}</label>
                            <select id="o-{{ $e->id }}" name="outcome" class="rounded-md border border-line px-3 py-1">@foreach (['resolved', 'ignored'] as $o)<option value="{{ $o }}">{{ __('payments::admin.outcome_options.'.$o) }}</option>@endforeach</select></div>
                        <div class="grow"><label for="n-{{ $e->id }}" class="block text-sm">{{ __('payments::admin.resolve_note') }}</label><input id="n-{{ $e->id }}" name="note" maxlength="255" required class="block w-full rounded-md border border-line px-3 py-1"></div>
                        <x-platform::btn type="submit" size="sm">{{ __('payments::admin.resolve_btn') }}</x-platform::btn>
                    </form>
                @endif
            </article>
        @empty
            <p class="text-muted">{{ __('payments::admin.no_items') }}</p>
        @endforelse
        {{ $exceptions->links() }}
    </section>

    @if ($can)
    <section aria-labelledby="imp-h">
        <form method="POST" action="{{ route('admin.payments.reconciliation.import') }}" enctype="multipart/form-data" class="space-y-3 rounded-lg border border-line p-4">
            @csrf
            <h2 id="imp-h" class="text-lg font-semibold">{{ __('payments::admin.import_title') }}</h2>
            <p class="text-sm text-muted">{{ __('payments::admin.import_help') }}</p>
            <div class="grid gap-3 sm:grid-cols-4">
                <div><label for="provider" class="block text-sm font-medium">{{ __('payments::admin.provider') }}</label>
                    <select id="provider" name="provider" required class="block w-full rounded-md border border-line px-3 py-2">@foreach ($providers as $p)<option value="{{ $p }}" @selected(old('provider') === $p)>{{ $p }}</option>@endforeach</select></div>
                <div><label for="from" class="block text-sm font-medium">{{ __('payments::admin.from') }}</label><input id="from" type="date" name="from" value="{{ old('from') }}" required class="block w-full rounded-md border border-line px-3 py-2"></div>
                <div><label for="to" class="block text-sm font-medium">{{ __('payments::admin.to') }}</label><input id="to" type="date" name="to" value="{{ old('to') }}" required class="block w-full rounded-md border border-line px-3 py-2"></div>
                <div><label for="file" class="block text-sm font-medium">{{ __('payments::admin.file') }}</label><input id="file" type="file" name="file" accept=".csv,.txt,text/csv,text/plain" required class="block w-full"></div>
            </div>
            @error('file')<p class="text-sm text-danger">{{ $message }}</p>@enderror
            <x-platform::btn type="submit" size="sm">{{ __('payments::admin.import_btn') }}</x-platform::btn>
        </form>
    </section>
    @endif

    <section aria-labelledby="hist-h" class="space-y-2">
        <h2 id="hist-h" class="text-lg font-semibold">{{ __('payments::admin.imports_title') }}</h2>
        <div class="overflow-x-auto"><table class="w-full text-sm">
            <caption class="sr-only">{{ __('payments::admin.imports_title') }}</caption>
            <thead><tr class="border-b border-line">@foreach (['provider', 'period', 'rows', 'matched', 'problems', 'when'] as $col)<th scope="col" class="py-2 pe-3 text-start">{{ __('payments::admin.import_cols.'.$col) }}</th>@endforeach</tr></thead>
            <tbody>
            @foreach ($imports as $i)
                <tr class="border-b border-line"><td class="py-2 pe-3">{{ $i->provider }}</td><td class="py-2 pe-3">{{ \App\Modules\Platform\Support\Fmt::dateTime($i->period_from) }} – {{ \App\Modules\Platform\Support\Fmt::dateTime($i->period_to) }}</td>
                    <td class="py-2 pe-3">{{ \App\Modules\Platform\Support\Fmt::number($i->row_count) }}</td><td class="py-2 pe-3">{{ \App\Modules\Platform\Support\Fmt::number($i->matched_count) }}</td><td class="py-2 pe-3">{{ \App\Modules\Platform\Support\Fmt::number($i->problem_count) }}</td><td class="py-2 pe-3">{{ \App\Modules\Platform\Support\Fmt::dateTime($i->created_at) }}</td></tr>
            @endforeach
            </tbody>
        </table></div>
    </section>
</div>
@endsection
