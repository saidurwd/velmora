@extends('layouts.admin')

@section('title', __('reporting::reports.title'))

@php
    use App\Modules\Platform\DTOs\Money;
    use App\Modules\Platform\Support\Fmt;
    use Carbon\CarbonImmutable;
    $show = fn (string $code, ?int $value, string $kind) => $value === null ? '—' : ($kind === 'money' ? Fmt::money(Money::of($value, 'BDT')) : ($kind === 'percent' ? Fmt::number($value / 100, 2).'%' : Fmt::number($value)));
    $dayName = fn (string $day) => Fmt::date(CarbonImmutable::parse($day, 'UTC'));
    $columns = array_values(array_filter($metrics, fn ($m) => in_array($m->code(), ['orders', 'gross_sales', 'discounts', 'billed_total', 'collected', 'refunded', 'net_sales'], true)));
@endphp

@section('content')
<div class="max-w-6xl space-y-6">
    <h1 class="text-2xl font-bold">{{ __('reporting::reports.title') }}</h1>
    <p class="text-sm text-muted">{{ __('reporting::reports.intro') }}</p>

    @if ($errors->any())
        <x-platform::alert type="danger"><ul class="list-disc ps-5">@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></x-platform::alert>
    @endif

    <form method="GET" action="{{ route('admin.reports.sales') }}" class="grid items-end gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <div><label for="from" class="block text-sm font-medium">{{ __('reporting::reports.from') }}</label>
            <input id="from" name="from" type="date" value="{{ $filter->from }}" max="{{ $today }}" class="mt-1 block w-full rounded-md border border-line px-3 py-2"></div>
        <div><label for="to" class="block text-sm font-medium">{{ __('reporting::reports.to') }}</label>
            <input id="to" name="to" type="date" value="{{ $filter->to }}" max="{{ $today }}" class="mt-1 block w-full rounded-md border border-line px-3 py-2"></div>
        <div><label for="channel" class="block text-sm font-medium">{{ __('reporting::reports.channel') }}</label>
            <select id="channel" name="channel" class="mt-1 block w-full rounded-md border border-line px-3 py-2"><option value="">{{ __('reporting::reports.any') }}</option>@foreach (['web', 'api', 'admin'] as $c)<option value="{{ $c }}" @selected($filter->channel === $c)>{{ __('reporting::reports.channels.'.$c) }}</option>@endforeach</select></div>
        <div><label for="method" class="block text-sm font-medium">{{ __('reporting::reports.method') }}</label>
            <select id="method" name="method" class="mt-1 block w-full rounded-md border border-line px-3 py-2"><option value="">{{ __('reporting::reports.any') }}</option>@foreach ($methods as $m)<option value="{{ $m['code'] }}" @selected($filter->method === $m['code'])>{{ $m['label'] }}</option>@endforeach</select></div>
        <div><label for="locale" class="block text-sm font-medium">{{ __('reporting::reports.locale') }}</label>
            <select id="locale" name="locale" class="mt-1 block w-full rounded-md border border-line px-3 py-2"><option value="">{{ __('reporting::reports.any') }}</option>@foreach (config('app.supported_locales') as $l)<option value="{{ $l }}" @selected($filter->locale === $l)>{{ __('reporting::reports.languages.'.$l) }}</option>@endforeach</select></div>
        <div><x-platform::btn type="submit" size="sm">{{ __('reporting::reports.filter') }}</x-platform::btn></div>
    </form>

    @if ($coverage['worked_out'] === 0)
        <x-platform::alert type="warning">{{ __('reporting::reports.nothing_worked_out') }}</x-platform::alert>
    @elseif ($coverage['missing'] !== [])
        <x-platform::alert type="warning">{{ __('reporting::reports.not_worked_out', ['missing' => Fmt::number(count($coverage['missing'])), 'days' => Fmt::number($coverage['days']), 'list' => implode(', ', array_map($dayName, array_slice($coverage['missing'], 0, 5))).(count($coverage['missing']) > 5 ? '…' : '')]) }}</x-platform::alert>
    @endif
    @if ($coverage['as_of'] !== null)
        <p class="text-sm text-muted">{{ __('reporting::reports.as_of', ['time' => Fmt::dateTime(CarbonImmutable::parse($coverage['as_of'], 'UTC'))]) }}</p>
    @endif

    <section aria-labelledby="totals-h" class="space-y-3">
        <h2 id="totals-h" class="text-lg font-semibold">{{ __('reporting::reports.totals') }}</h2>
        <p class="text-sm text-muted">{{ __('reporting::reports.money_note') }}</p>
        <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($metrics as $metric)
                <div class="rounded-lg border border-line p-4">
                    <dt class="text-sm text-muted">{{ __('reporting::metrics.'.$metric->code().'.label') }}</dt>
                    <dd class="text-2xl font-bold">{{ $show($metric->code(), $totals[$metric->code()] ?? null, $metric->kind()) }}</dd>
                    <dd class="mt-1 text-xs text-muted">{{ __('reporting::metrics.'.$metric->code().'.definition') }}</dd>
                </div>
            @endforeach
        </dl>
        @unless ($finance)<p class="text-sm text-muted">{{ __('reporting::reports.finance_note') }}</p>@endunless
    </section>

    <section aria-labelledby="days-h" class="space-y-3">
        <h2 id="days-h" class="text-lg font-semibold">{{ __('reporting::reports.by_day') }}</h2>
        <div class="overflow-x-auto"><table class="w-full text-left text-sm">
            <caption class="sr-only">{{ __('reporting::reports.by_day') }}</caption>
            <thead><tr class="border-b border-line"><th scope="col" class="py-2 pe-3">{{ __('reporting::reports.day') }}</th>@foreach ($columns as $c)<th scope="col" class="py-2 pe-3 text-end">{{ __('reporting::metrics.'.$c->code().'.label') }}</th>@endforeach</tr></thead>
            <tbody>
            @foreach ($perDay as $day => $values)
                <tr class="border-b border-line align-top">
                    <th scope="row" class="py-2 pe-3 font-normal">{{ $dayName($day) }}</th>
                    @foreach ($columns as $c)
                        <td class="py-2 pe-3 text-end">{{ $values === null ? '—' : $show($c->code(), $values[$c->code()] ?? null, $c->kind()) }}</td>
                    @endforeach
                </tr>
            @endforeach
            </tbody></table></div>
    </section>
</div>
@endsection
