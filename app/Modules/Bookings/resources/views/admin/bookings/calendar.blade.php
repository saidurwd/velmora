@extends('layouts.admin')

@section('title', __('bookings::admin.menu.calendar'))

@php
    $zone = config('bookings.timezone');
    $time = fn ($d) => $d->setTimezone($zone)->format('H:i');
    $prev = \Carbon\CarbonImmutable::parse($start, $zone)->subDays($view === 'week' ? 7 : 1)->format('Y-m-d');
    $next = \Carbon\CarbonImmutable::parse($start, $zone)->addDays($view === 'week' ? 7 : 1)->format('Y-m-d');
    $link = fn (array $q) => route('admin.bookings.calendar', array_filter($q + ['view' => $view, 'resource' => $resource]));
@endphp

@section('content')
<div class="max-w-6xl space-y-6">
    <header class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-bold">{{ __('bookings::admin.menu.calendar') }}</h1>
        <a href="{{ route('admin.bookings.index') }}" class="inline-flex min-h-11 items-center underline">{{ __('bookings::admin.menu.bookings') }}</a>
    </header>
    <form method="GET" action="{{ route('admin.bookings.calendar') }}" class="flex flex-wrap items-end gap-3">
        <div><label for="view" class="block text-sm font-medium">{{ __('bookings::admin.view') }}</label>
            <select id="view" name="view" class="mt-1 rounded-md border border-line px-3 py-2"><option value="day" @selected($view === 'day')>{{ __('bookings::admin.day') }}</option><option value="week" @selected($view === 'week')>{{ __('bookings::admin.week') }}</option></select></div>
        <div><label for="date" class="block text-sm font-medium">{{ __('bookings::admin.date') }}</label><input id="date" type="date" name="date" value="{{ $date }}" class="mt-1 rounded-md border border-line px-3 py-2"></div>
        <div><label for="resource" class="block text-sm font-medium">{{ __('bookings::admin.col.resource') }}</label>
            <select id="resource" name="resource" class="mt-1 rounded-md border border-line px-3 py-2"><option value="">{{ __('bookings::admin.any') }}</option>
                @foreach ($allResources as $r)<option value="{{ $r->public_id }}" @selected($resource === $r->public_id)>{{ $r->name }}</option>@endforeach</select></div>
        <x-platform::btn type="submit" size="sm">{{ __('bookings::admin.show') }}</x-platform::btn>
    </form>
    <nav class="flex gap-4" aria-label="{{ __('bookings::admin.navigate') }}">
        <a href="{{ $link(['date' => $prev]) }}" class="inline-flex min-h-11 items-center underline">{{ __('bookings::admin.previous') }}</a>
        <a href="{{ $link(['date' => $next]) }}" class="inline-flex min-h-11 items-center underline">{{ __('bookings::admin.next') }}</a>
    </nav>
    @if ($cal['capped'])<x-platform::alert type="error">{{ __('bookings::admin.capped') }}</x-platform::alert>@endif

    @if ($cal['resources'] === [])
        <p class="text-muted">{{ __('bookings::admin.no_resources') }}</p>
    @else
    <div class="overflow-x-auto">
    <table class="w-full text-left text-sm">
        <caption class="sr-only">{{ __('bookings::admin.menu.calendar') }}</caption>
        <thead><tr class="border-b border-line"><th scope="col" class="py-2 pr-3">{{ __('bookings::admin.date') }}</th>
            @foreach ($cal['resources'] as $r)<th scope="col" class="py-2 pr-3">{{ $r['name'] }}</th>@endforeach</tr></thead>
        <tbody>
        @foreach ($cal['days'] as $day)
            <tr class="border-b border-line align-top">
                <th scope="row" class="py-2 pr-3 font-medium">{{ \Carbon\CarbonImmutable::parse($day)->locale(app()->getLocale())->isoFormat('ddd, D MMM') }}</th>
                @foreach ($cal['resources'] as $r)
                <td class="py-2 pr-3">
                    @forelse ($cal['cells'][$day][$r['public_id']] ?? [] as $b)
                        <a href="{{ route('admin.bookings.show', ['booking' => $b['public_id']]) }}" class="mb-1 flex min-h-11 flex-col justify-center rounded-md border border-line px-2 py-1 hover:bg-surface-alt">
                            <span>{{ $time($b['starts_at']) }}–{{ $time($b['ends_at']) }} · {{ $b['service'] }}</span>
                            <span class="text-xs text-muted">{{ __('bookings::mine.status.'.$b['status']) }}</span>
                        </a>
                    @empty
                        <span class="text-muted">—</span>
                    @endforelse
                </td>
                @endforeach
            </tr>
        @endforeach
        </tbody>
    </table>
    </div>
    <p class="text-sm text-muted">{{ __('bookings::admin.zone_note', ['zone' => $zone]) }}</p>
    @endif
</div>
@endsection
