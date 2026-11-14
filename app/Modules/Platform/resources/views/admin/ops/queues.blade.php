@extends('layouts.admin')

@php
    use App\Modules\Platform\Support\Fmt;
    use Carbon\CarbonImmutable;

    // "4 min", "2 h 5 min": how long, in the current language
    $ago = function (?int $s): string {
        if ($s === null) {
            return '—';
        }

        return match (true) {
            $s < 60 => __('platform::security.duration.seconds', ['n' => Fmt::number($s)]),
            $s < 3600 => __('platform::security.duration.minutes', ['n' => Fmt::number(intdiv($s, 60))]),
            $s < 86400 => __('platform::security.duration.hours', ['h' => Fmt::number(intdiv($s, 3600)), 'm' => Fmt::number(intdiv($s % 3600, 60))]),
            default => __('platform::security.duration.days', ['n' => Fmt::number(intdiv($s, 86400))]),
        };
    };
    $tone = ['ok' => 'success', 'warn' => 'warning', 'critical' => 'danger', 'unknown' => 'neutral'];
@endphp

@section('title', __('platform::ops.queues.title'))

@section('content')
<div class="space-y-8">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold">{{ __('platform::ops.queues.title') }}</h1>
            <p class="mt-1 text-sm text-muted">{{ __('platform::ops.queues.intro') }}</p>
        </div>
        <x-platform::btn variant="secondary" :href="route('admin.ops.queues')">{{ __('platform::ops.queues.refresh') }}</x-platform::btn>
    </div>

    <dl class="grid gap-3 sm:grid-cols-3">
        <div class="rounded-lg border border-line p-4"><dt class="text-sm text-muted">{{ __('platform::ops.queues.connection') }}</dt><dd class="mt-1 font-semibold">{{ $q['connection'] }}</dd></div>
        <div class="rounded-lg border border-line p-4"><dt class="text-sm text-muted">{{ __('platform::ops.queues.runner') }}</dt><dd class="mt-1 font-semibold">{{ __('platform::ops.queues.runners.'.$q['runner']) }}</dd></div>
        <div @class(['rounded-lg border border-line p-4', 'border-s-4 border-s-danger' => $q['scheduler']['stale']])><dt class="text-sm text-muted">{{ __('platform::ops.health.scheduler') }}</dt>
            <dd class="mt-1 font-semibold">{{ $q['scheduler']['age'] === null ? __('platform::ops.health.never') : __('platform::ops.health.seconds_ago', ['seconds' => Fmt::number($q['scheduler']['age'])]) }}</dd></div>
    </dl>

    <section aria-labelledby="queues" class="space-y-3">
        <h2 id="queues" class="text-lg font-semibold">{{ __('platform::ops.queues.queues') }}</h2>
        <p class="text-sm text-muted">{{ __('platform::ops.queues.thresholds', ['warn' => $ago($q['thresholds']['warn']), 'critical' => $ago($q['thresholds']['critical'])]) }}</p>
        <div class="overflow-x-auto" tabindex="0" role="region" aria-label="{{ __('platform::ops.queues.queues') }}">
            <table class="w-full text-sm">
                <caption class="sr-only">{{ __('platform::ops.queues.queues') }}</caption>
                <thead><tr class="border-b border-line">
                    <th scope="col" class="py-2 pe-3 text-start">{{ __('platform::ops.queues.columns.queue') }}</th>
                    <th scope="col" class="py-2 pe-3 text-end">{{ __('platform::ops.queues.columns.pending') }}</th>
                    <th scope="col" class="py-2 pe-3 text-end">{{ __('platform::ops.queues.columns.delayed') }}</th>
                    <th scope="col" class="py-2 pe-3 text-end">{{ __('platform::ops.queues.columns.running') }}</th>
                    <th scope="col" class="py-2 pe-3 text-start">{{ __('platform::ops.queues.columns.oldest') }}</th>
                    <th scope="col" class="py-2 text-start">{{ __('platform::ops.queues.columns.state') }}</th>
                </tr></thead>
                <tbody>
                @foreach ($q['queues'] as $row)
                    <tr class="border-b border-line">
                        <th scope="row" class="py-2 pe-3 text-start font-mono font-medium">{{ $row['name'] }}</th>
                        <td class="py-2 pe-3 text-end">{{ $row['pending'] === null ? '—' : Fmt::number($row['pending']) }}</td>
                        <td class="py-2 pe-3 text-end">{{ $row['delayed'] === null ? '—' : Fmt::number($row['delayed']) }}</td>
                        <td class="py-2 pe-3 text-end">{{ $row['running'] === null ? '—' : Fmt::number($row['running']) }}</td>
                        <td class="py-2 pe-3">{{ $ago($row['oldest']) }}</td>
                        <td class="py-2"><x-platform::badge :tone="$tone[$row['state']]">{{ __('platform::ops.queues.states.'.$row['state']) }}</x-platform::badge></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <div class="grid gap-6 lg:grid-cols-2">
        <section aria-labelledby="failed" class="space-y-3 rounded-lg border border-line p-4">
            <div class="flex items-center justify-between gap-4">
                <h2 id="failed" class="text-lg font-semibold">{{ __('platform::ops.queues.failed') }}</h2>
                @can('queues.view')<a class="text-sm underline" href="{{ route('admin.ops.failed') }}">{{ __('platform::ops.queues.see_failed', ['n' => Fmt::number($q['failed']['total'])]) }}</a>@endcan
            </div>
            @if ($q['failed']['kinds'] === [])
                <p class="flex items-center gap-2 text-sm"><svg aria-hidden="true" class="size-4 text-success" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="m8 12.5 2.5 2.5L16 9.5"/></svg>{{ __('platform::ops.queues.no_failures') }}</p>
            @else
                <ol class="divide-y divide-line text-sm">
                    @foreach ($q['failed']['kinds'] as $k)
                        <li class="flex items-center justify-between gap-4 py-2"><span class="font-mono">{{ $k['kind'] }}</span><span class="text-muted">{{ Fmt::number($k['count']) }} · {{ Fmt::relative(CarbonImmutable::parse($k['last'], 'UTC')) }}</span></li>
                    @endforeach
                </ol>
            @endif
        </section>
        <section aria-labelledby="outbox" class="space-y-3 rounded-lg border border-line p-4">
            <h2 id="outbox" class="text-lg font-semibold">{{ __('platform::ops.queues.outbox') }}</h2>
            <p class="text-sm text-muted">{{ __('platform::ops.queues.outbox_help') }}</p>
            <dl class="grid grid-cols-3 gap-3 text-sm">
                <div><dt class="text-muted">{{ __('platform::ops.queues.outbox_pending') }}</dt><dd class="text-xl font-bold tabular-nums">{{ Fmt::number($q['outbox']['pending']) }}</dd></div>
                <div><dt class="text-muted">{{ __('platform::ops.queues.outbox_failed') }}</dt><dd @class(['text-xl font-bold tabular-nums', 'text-danger' => $q['outbox']['failed'] > 0])>{{ Fmt::number($q['outbox']['failed']) }}</dd></div>
                <div><dt class="text-muted">{{ __('platform::ops.queues.columns.oldest') }}</dt><dd class="text-xl font-bold">{{ $ago($q['outbox']['oldest']) }}</dd></div>
            </dl>
        </section>
    </div>
</div>
@endsection
