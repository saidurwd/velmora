@extends('layouts.admin')

@php
    use App\Modules\Platform\Services\AuditLogQuery;
    use App\Modules\Platform\Support\AuditCatalog;
    use App\Modules\Platform\Support\Fmt;
    use Carbon\CarbonImmutable;

    $tone = ['high' => 'danger', 'medium' => 'warning', 'info' => 'neutral'];
    $tiles = [
        ['auth.login_failed', 'failed_passwords', false], ['auth.mfa_failed', 'failed_codes', false], ['auth.otp_failed', 'failed_otps', false],
        ['auth.account_locked', 'lockouts', true], ['auth.recovery_code_used', 'recovery_codes', true], ['auth.api_refresh_reuse', 'key_reuse', true],
    ];
@endphp

@section('title', __('platform::security.events.title'))

@section('content')
<div class="space-y-8">
    <div>
        <h1 class="text-2xl font-bold">{{ __('platform::security.events.title') }}</h1>
        <p class="mt-1 text-sm text-muted">{{ __('platform::security.events.intro') }}</p>
    </div>

    <section aria-labelledby="last-day" class="space-y-3">
        <h2 id="last-day" class="text-lg font-semibold">{{ __('platform::security.events.last_day') }}</h2>
        <ul class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($tiles as [$action, $key, $serious])
                @php($n = $counts[$action] ?? 0)
                <li @class(['rounded-lg border border-line p-4', 'border-s-4 border-s-danger' => $serious && $n > 0])>
                    <p class="text-sm text-muted">{{ __('platform::security.events.tiles.'.$key) }}</p>
                    <p class="mt-1 text-3xl font-bold tabular-nums">{{ Fmt::number($n) }}</p>
                </li>
            @endforeach
        </ul>
        <div class="rounded-lg border border-line p-4">
            <h3 class="font-semibold">{{ __('platform::security.events.sources') }}</h3>
            <p class="text-sm text-muted">{{ __('platform::security.events.sources_help') }}</p>
            @if ($sources === [])
                <p class="mt-3 flex items-center gap-2 text-sm"><svg aria-hidden="true" class="size-4 text-success" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="m8 12.5 2.5 2.5L16 9.5"/></svg>{{ __('platform::security.events.no_sources') }}</p>
            @else
                <ol class="mt-3 divide-y divide-line text-sm">
                    @foreach ($sources as $s)
                        <li class="flex items-center justify-between gap-4 py-2"><span class="font-mono">{{ $s['ip'] }}</span><span class="text-muted">{{ trans_choice('platform::security.events.failures', $s['count'], ['count' => Fmt::number($s['count'])]) }} · {{ Fmt::relative(CarbonImmutable::parse($s['last'], 'UTC')) }}</span></li>
                    @endforeach
                </ol>
            @endif
        </div>
    </section>

    <section aria-labelledby="feed" class="space-y-3">
        <h2 id="feed" class="text-lg font-semibold">{{ __('platform::security.events.feed') }}</h2>
        <x-platform::form.errors />
        <form method="GET" action="{{ route('admin.security.events') }}" class="grid gap-3 rounded-lg border border-line p-4 sm:grid-cols-2 lg:grid-cols-4" novalidate>
            <div><label for="severity" class="block text-sm font-medium">{{ __('platform::security.filters.severity') }}</label>
                <select id="severity" name="severity" class="mt-1 w-full rounded-md border border-line px-3 py-2"><option value="">{{ __('platform::security.filters.any') }}</option>@foreach (['high', 'medium', 'info'] as $s)<option value="{{ $s }}" @selected($filters['severity'] === $s)>{{ __('platform::security.severity.'.$s) }}</option>@endforeach</select></div>
            <div><label for="from" class="block text-sm font-medium">{{ __('platform::security.filters.from') }}</label><input id="from" name="from" type="date" value="{{ $filters['from'] }}" class="mt-1 w-full rounded-md border border-line px-3 py-2"></div>
            <div><label for="to" class="block text-sm font-medium">{{ __('platform::security.filters.to') }}</label><input id="to" name="to" type="date" value="{{ $filters['to'] }}" class="mt-1 w-full rounded-md border border-line px-3 py-2"></div>
            <div class="flex items-end gap-2"><x-platform::form.button>{{ __('platform::security.filter') }}</x-platform::form.button><x-platform::btn variant="secondary" :href="route('admin.security.events')">{{ __('platform::security.clear') }}</x-platform::btn></div>
        </form>

        <div class="overflow-x-auto" tabindex="0" role="region" aria-label="{{ __('platform::security.events.feed') }}">
            <table class="w-full text-sm">
                <caption class="sr-only">{{ __('platform::security.events.feed') }}</caption>
                <thead><tr class="border-b border-line">
                    <th scope="col" class="py-2 pe-3 text-start">{{ __('platform::security.columns.time') }}</th>
                    <th scope="col" class="py-2 pe-3 text-start">{{ __('platform::security.columns.severity') }}</th>
                    <th scope="col" class="py-2 pe-3 text-start">{{ __('platform::security.columns.event') }}</th>
                    <th scope="col" class="py-2 pe-3 text-start">{{ __('platform::security.columns.account') }}</th>
                    <th scope="col" class="py-2 pe-3 text-start">{{ __('platform::security.columns.ip') }}</th>
                    <th scope="col" class="py-2 text-start"><span class="sr-only">{{ __('platform::security.details') }}</span></th>
                </tr></thead>
                <tbody>
                @forelse ($page['rows'] as $r)
                    @php($sev = AuditCatalog::severity($r->action))
                    @php($by = $people[$r->id]['actor'] ?? null)
                    <tr class="border-b border-line align-top">
                        <td class="py-2 pe-3 whitespace-nowrap">{{ Fmt::dateTime(CarbonImmutable::parse($r->created_at, 'UTC')) }}</td>
                        <td class="py-2 pe-3"><x-platform::badge :tone="$tone[$sev]">{{ __('platform::security.severity.'.$sev) }}</x-platform::badge></td>
                        <td class="py-2 pe-3"><strong class="font-semibold">{{ $by?->name ?? __('platform::security.actor.'.$r->actor_type) }}</strong> {{ AuditCatalog::sentence($r->action) }}</td>
                        <td class="py-2 pe-3">@include('platform::admin.security._person', ['person' => $people[$r->id]['subject'] ?? null, 'fallback' => '—'])</td>
                        <td class="py-2 pe-3 font-mono">{{ AuditLogQuery::maskIp($r->ip) ?: '—' }}</td>
                        <td class="py-2"><a class="underline" href="{{ route('admin.audit.show', $r->id) }}">{{ __('platform::security.details') }}</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-6 text-center text-muted">{{ __('platform::security.events.empty') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($page['next'])<x-platform::btn variant="secondary" :href="route('admin.security.events', array_filter($filters) + ['before' => $page['next']])">{{ __('platform::security.older') }}</x-platform::btn>@endif
    </section>
</div>
@endsection
