@extends('layouts.admin')

@section('title', __('shipping::fulfilment.statements.title'))

@php
    $fmt = fn (int $minor, string $cur = 'BDT') => \App\Modules\Platform\Support\Fmt::money(\App\Modules\Platform\DTOs\Money::of($minor, $cur));
@endphp

@section('content')
<div class="space-y-8">
    <h1 class="text-2xl font-bold">{{ __('shipping::fulfilment.statements.title') }}</h1>
    <p class="text-sm text-muted">{{ __('shipping::fulfilment.statements.help') }}</p>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    @error('statement')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror

    @if ($can)
    <form method="POST" action="{{ route('admin.shipping.statements.import') }}" enctype="multipart/form-data" class="space-y-3 rounded-lg border border-line p-4">@csrf
        <h2 class="text-lg font-semibold">{{ __('shipping::fulfilment.statements.import') }}</h2>
        <div class="grid gap-3 sm:grid-cols-3">
            <div><label for="courier" class="block text-sm font-medium">{{ __('shipping::fulfilment.statements.courier') }}</label>
                <select id="courier" name="courier" required class="block w-full rounded-md border border-line px-3 py-2">@foreach ($couriers as $c)<option value="{{ $c }}" @selected(old('courier') === $c)>{{ $c }}</option>@endforeach</select></div>
            <div><label for="statement" class="block text-sm font-medium">{{ __('shipping::fulfilment.statements.number') }}</label><input id="statement" name="statement" value="{{ old('statement') }}" required maxlength="80" class="block w-full rounded-md border border-line px-3 py-2"></div>
            <div><label for="received" class="block text-sm font-medium">{{ __('shipping::fulfilment.statements.received') }}</label><input id="received" name="received" value="{{ old('received') }}" required inputmode="decimal" class="block w-full rounded-md border border-line px-3 py-2"></div>
            <div><label for="from" class="block text-sm font-medium">{{ __('shipping::fulfilment.statements.from') }}</label><input id="from" type="date" name="from" value="{{ old('from') }}" required class="block w-full rounded-md border border-line px-3 py-2"></div>
            <div><label for="to" class="block text-sm font-medium">{{ __('shipping::fulfilment.statements.to') }}</label><input id="to" type="date" name="to" value="{{ old('to') }}" required class="block w-full rounded-md border border-line px-3 py-2"></div>
            <div><label for="file" class="block text-sm font-medium">{{ __('shipping::fulfilment.statements.file') }}</label><input id="file" type="file" name="file" accept=".csv,text/csv,text/plain" required class="block w-full text-sm"></div>
        </div>
        <x-platform::btn type="submit" size="sm">{{ __('shipping::fulfilment.statements.import_btn') }}</x-platform::btn>
    </form>
    @else
    <p class="text-sm text-muted">{{ __('shipping::fulfilment.statements.only_view') }}</p>
    @endif

    @if ($statements->isEmpty())
        <p class="text-muted">{{ __('shipping::fulfilment.statements.empty') }}</p>
    @else
    <div class="overflow-x-auto"><table class="w-full text-sm">
        <caption class="sr-only">{{ __('shipping::fulfilment.statements.title') }}</caption>
        <thead><tr class="border-b border-line">@foreach (['number', 'courier', 'period', 'received', 'fee', 'lines', 'matched', 'problems'] as $col)<th scope="col" class="py-2 pe-3 text-start">{{ __('shipping::fulfilment.statements.cols.'.$col) }}</th>@endforeach</tr></thead>
        <tbody>
        @foreach ($statements as $s)
            <tr class="border-b border-line">
                <td class="py-2 pe-3"><a class="underline" href="{{ route('admin.shipping.statements.show', $s->public_id) }}">{{ $s->statement_ref }}</a></td><td class="py-2 pe-3">{{ $s->courier }}</td>
                <td class="py-2 pe-3">{{ \App\Modules\Platform\Support\Fmt::date($s->period_from) }} – {{ \App\Modules\Platform\Support\Fmt::date($s->period_to) }}</td>
                <td class="py-2 pe-3">{{ $fmt($s->received_minor, $s->currency) }}</td><td class="py-2 pe-3">{{ $fmt($s->fee_minor, $s->currency) }}</td>
                <td class="py-2 pe-3">{{ $s->line_count }}</td><td class="py-2 pe-3">{{ $s->matched_count }}</td><td class="py-2 pe-3">{{ $s->problem_count }}</td></tr>
        @endforeach
        </tbody>
    </table></div>
    {{ $statements->links() }}
    @endif
</div>
@endsection
