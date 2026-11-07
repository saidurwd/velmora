@extends('layouts.admin')

@section('title', __('reporting::catalogue.'.$report->code().'.title'))

@php
    use App\Modules\Platform\DTOs\Money;
    use App\Modules\Platform\Support\Fmt;
    use Carbon\CarbonImmutable;
    $supports = fn (string $f) => in_array($f, $report->filters(), true);
    $cell = function ($column, $value) {
        if ($value === null) {
            return '—';
        }
        return match ($column->kind) {
            'money' => Fmt::money(Money::of((int) $value, 'BDT')),
            'count' => Fmt::number((int) $value),
            'percent' => Fmt::number(((int) $value) / 100, 2).'%',
            'date' => Fmt::date(CarbonImmutable::parse((string) $value, 'UTC')),
            default => (string) $value,
        };
    };
@endphp

@section('content')
<div class="max-w-6xl space-y-6">
    <p><a class="text-sm underline" href="{{ route('admin.reports.index') }}">{{ __('reporting::reports.all_reports') }}</a></p>
    <h1 class="text-2xl font-bold">{{ __('reporting::catalogue.'.$report->code().'.title') }}</h1>
    <p class="text-sm text-muted">{{ __('reporting::catalogue.'.$report->code().'.description') }}</p>

    @if ($errors->any())
        <x-platform::alert type="danger"><ul class="list-disc ps-5">@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></x-platform::alert>
    @endif
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif

    @if ($report->filters() !== [])
    <form method="GET" action="{{ route('admin.reports.show', ['code' => $report->code()]) }}" class="grid items-end gap-3 sm:grid-cols-2 lg:grid-cols-3">
        @if ($supports('period'))
            <div><label for="from" class="block text-sm font-medium">{{ __('reporting::reports.from') }}</label>
                <input id="from" name="from" type="date" value="{{ $filter->from }}" max="{{ $today }}" class="mt-1 block w-full rounded-md border border-line px-3 py-2"></div>
            <div><label for="to" class="block text-sm font-medium">{{ __('reporting::reports.to') }}</label>
                <input id="to" name="to" type="date" value="{{ $filter->to }}" max="{{ $today }}" class="mt-1 block w-full rounded-md border border-line px-3 py-2"></div>
        @endif
        @if ($supports('channel'))
            <div><label for="channel" class="block text-sm font-medium">{{ __('reporting::reports.channel') }}</label>
                <select id="channel" name="channel" class="mt-1 block w-full rounded-md border border-line px-3 py-2"><option value="">{{ __('reporting::reports.any') }}</option>@foreach (['web', 'api', 'admin'] as $c)<option value="{{ $c }}" @selected($filter->channel === $c)>{{ __('reporting::reports.channels.'.$c) }}</option>@endforeach</select></div>
        @endif
        @if ($supports('method'))
            <div><label for="method" class="block text-sm font-medium">{{ __('reporting::reports.method') }}</label>
                <select id="method" name="method" class="mt-1 block w-full rounded-md border border-line px-3 py-2"><option value="">{{ __('reporting::reports.any') }}</option>@foreach ($methods as $m)<option value="{{ $m['code'] }}" @selected($filter->method === $m['code'])>{{ $m['label'] }}</option>@endforeach</select></div>
        @endif
        @if ($supports('locale'))
            <div><label for="locale" class="block text-sm font-medium">{{ __('reporting::reports.locale') }}</label>
                <select id="locale" name="locale" class="mt-1 block w-full rounded-md border border-line px-3 py-2"><option value="">{{ __('reporting::reports.any') }}</option>@foreach (config('app.supported_locales') as $l)<option value="{{ $l }}" @selected($filter->locale === $l)>{{ __('reporting::reports.languages.'.$l) }}</option>@endforeach</select></div>
        @endif
        <div><x-platform::btn type="submit" size="sm">{{ __('reporting::reports.filter') }}</x-platform::btn></div>
    </form>
    @endif

    @if ($coverage !== null && $coverage['worked_out'] === 0)
        <x-platform::alert type="warning">{{ __('reporting::reports.nothing_worked_out') }}</x-platform::alert>
    @elseif ($coverage !== null && $coverage['missing'] !== [])
        <x-platform::alert type="warning">{{ __('reporting::reports.not_worked_out', ['missing' => Fmt::number(count($coverage['missing'])), 'days' => Fmt::number($coverage['days']), 'list' => implode(', ', array_map(fn ($d) => Fmt::date(CarbonImmutable::parse($d, 'UTC')), array_slice($coverage['missing'], 0, 5))).(count($coverage['missing']) > 5 ? '…' : '')]) }}</x-platform::alert>
    @endif

    @if ($canExport)
    <form method="POST" action="{{ route('admin.reports.export', ['code' => $report->code()]) }}" class="flex items-center gap-3">
        @csrf
        @foreach ($filter->query() as $name => $value)@if ($supports($name === 'from' || $name === 'to' ? 'period' : $name))<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endif @endforeach
        <x-platform::btn type="submit" size="sm" variant="secondary">{{ __('reporting::exports.ask') }}</x-platform::btn>
        <span class="text-sm text-muted">{{ __('reporting::exports.ask_help') }}</span>
    </form>
    @endif

    @if ($rows === [])
        <p class="text-muted">{{ __('reporting::reports.no_figures') }}</p>
    @else
    <div class="overflow-x-auto"><table class="w-full text-left text-sm">
        <caption class="sr-only">{{ __('reporting::catalogue.'.$report->code().'.title') }}</caption>
        <thead><tr class="border-b border-line">@foreach ($columns as $c)<th scope="col" class="py-2 pe-3 {{ $values->isNumeric($c) ? 'text-end' : '' }}">{{ $values->label($report, $c->key) }}</th>@endforeach</tr></thead>
        <tbody>
        @foreach ($rows as $row)
            <tr class="border-b border-line align-top">
                @foreach ($columns as $i => $c)
                    @if ($i === 0)<th scope="row" class="py-2 pe-3 font-normal">{{ $cell($c, $row[$c->key] ?? null) }}</th>
                    @else<td class="py-2 pe-3 {{ $values->isNumeric($c) ? 'text-end' : '' }}">{{ $cell($c, $row[$c->key] ?? null) }}</td>@endif
                @endforeach
            </tr>
        @endforeach
        </tbody></table></div>
        @if ($more)<p class="text-sm text-muted">{{ __('reporting::reports.first_rows', ['count' => Fmt::number($limit)]) }}</p>@endif
    @endif
</div>
@endsection
