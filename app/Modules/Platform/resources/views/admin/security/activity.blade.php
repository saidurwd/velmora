@extends('layouts.admin')

@php
    use App\Modules\Platform\Support\AuditCatalog;
    use App\Modules\Platform\Support\Fmt;
    use Carbon\CarbonImmutable;

    $title = $mine ? __('platform::security.activity.mine_title') : __('platform::security.activity.title');
    $route = $mine ? 'admin.account.activity' : 'admin.security.activity';
    $zone = (string) config('app.display_timezone');
    $byDay = $page['rows']->groupBy(fn ($r) => CarbonImmutable::parse($r->created_at, 'UTC')->setTimezone($zone)->toDateString());
    $areas = array_values(array_unique(AuditCatalog::AREAS));
    $canAudit = auth('admin')->user()?->can('audit.view') ?? false;
@endphp

@section('title', $title)

@section('content')
<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold">{{ $title }}</h1>
            <p class="mt-1 text-sm text-muted">{{ $mine ? __('platform::security.activity.mine_intro') : __('platform::security.activity.intro') }}</p>
        </div>
        @if (! $mine)<x-platform::btn variant="secondary" :href="route('admin.account.activity')">{{ __('platform::security.activity.mine_title') }}</x-platform::btn>@endif
    </div>
    <x-platform::form.errors />

    <form method="GET" action="{{ route($route) }}" class="grid gap-3 rounded-lg border border-line p-4 sm:grid-cols-2 lg:grid-cols-5" novalidate>
        <div><label for="from" class="block text-sm font-medium">{{ __('platform::security.filters.from') }}</label><input id="from" name="from" type="date" value="{{ $filters['from'] }}" class="mt-1 w-full rounded-md border border-line px-3 py-2"></div>
        <div><label for="to" class="block text-sm font-medium">{{ __('platform::security.filters.to') }}</label><input id="to" name="to" type="date" value="{{ $filters['to'] }}" class="mt-1 w-full rounded-md border border-line px-3 py-2"></div>
        @unless ($mine)
            <div><label for="person" class="block text-sm font-medium">{{ __('platform::security.filters.staff') }}</label>
                <select id="person" name="person" class="mt-1 w-full rounded-md border border-line px-3 py-2"><option value="">{{ __('platform::security.filters.everyone') }}</option>@foreach ($staff as $p)<option value="{{ $p->id }}" @selected($filters['person'] === (string) $p->id)>{{ $p->name }}</option>@endforeach</select></div>
        @endunless
        <div><label for="area" class="block text-sm font-medium">{{ __('platform::security.filters.area') }}</label>
            <select id="area" name="area" class="mt-1 w-full rounded-md border border-line px-3 py-2"><option value="">{{ __('platform::security.filters.all_areas') }}</option>@foreach ($areas as $a)<option value="{{ $a }}" @selected($filters['area'] === $a)>{{ __('layout.nav.'.$a) }}</option>@endforeach</select></div>
        <div class="flex items-end gap-2"><x-platform::form.button>{{ __('platform::security.filter') }}</x-platform::form.button><x-platform::btn variant="secondary" :href="route($route)">{{ __('platform::security.clear') }}</x-platform::btn></div>
    </form>

    @if ($page['rows']->isEmpty())
        <x-platform::empty-state :title="__('platform::security.activity.empty')" />
    @else
        <div class="space-y-6">
            @foreach ($byDay as $day => $rows)
                <section aria-labelledby="day-{{ $day }}">
                    <h2 id="day-{{ $day }}" class="mb-2 text-xs font-semibold uppercase tracking-wider text-muted">{{ Fmt::date(CarbonImmutable::parse($day, $zone), 'full') }}</h2>
                    <ol class="divide-y divide-line overflow-hidden rounded-lg border border-line bg-surface">
                        @foreach ($rows as $r)
                            @php($who = $people[$r->id]['actor'] ?? null)
                            <li class="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3">
                                <time datetime="{{ CarbonImmutable::parse($r->created_at, 'UTC')->toIso8601String() }}" class="w-16 shrink-0 text-sm tabular-nums text-muted">{{ CarbonImmutable::parse($r->created_at, 'UTC')->setTimezone($zone)->format('H:i') }}</time>
                                <p class="min-w-0 flex-1"><strong class="font-semibold">{{ $who?->name ?? __('platform::security.unknown_person') }}</strong> {{ AuditCatalog::sentence($r->action) }}</p>
                                <x-platform::badge>{{ __('layout.nav.'.AuditCatalog::area($r->action)) }}</x-platform::badge>
                                @if ($canAudit)<a class="text-sm underline" href="{{ route('admin.audit.show', $r->id) }}">{{ __('platform::security.details') }}<span class="sr-only"> — {{ $r->action }}</span></a>@endif
                            </li>
                        @endforeach
                    </ol>
                </section>
            @endforeach
        </div>
    @endif
    @if ($page['next'])<x-platform::btn variant="secondary" :href="route($route, array_filter($filters) + ['before' => $page['next']])">{{ __('platform::security.older') }}</x-platform::btn>@endif
</div>
@endsection
