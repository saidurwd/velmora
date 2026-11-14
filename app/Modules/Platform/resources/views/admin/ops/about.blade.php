@extends('layouts.admin')

@php
    use App\Modules\Platform\Support\Fmt;

    $yes = fn (bool $b) => $b ? __('platform::ops.health.on') : __('platform::ops.health.off');
    $languages = collect($a['languages']['supported'])->map(fn ($l) => __('platform::ops.about.language_names.'.$l))->implode(', ');
@endphp

@section('title', __('platform::ops.about.title'))

@section('content')
<div class="max-w-5xl space-y-8">
    <div class="flex flex-wrap items-center gap-5">
        @if ($logo = app(\App\Modules\Platform\Support\Branding::class)->logo())<img src="{{ $logo }}" alt="" width="335" height="100" class="h-14 w-auto">@endif
        <div>
            <h1 class="text-2xl font-bold">{{ __('platform::ops.about.title') }}</h1>
            <p class="mt-1 text-sm text-muted">{{ __('platform::ops.about.intro', ['shop' => $a['shop']]) }}</p>
        </div>
    </div>

    <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-lg border border-line p-4"><dt class="text-sm text-muted">{{ __('platform::ops.about.release') }}</dt><dd class="mt-1 font-mono font-semibold">{{ $a['release']['id'] ?? __('platform::ops.about.development') }}</dd>
            @if ($a['release']['built'])<dd class="text-xs text-muted">{{ __('platform::ops.about.built', ['when' => Fmt::dateTime($a['release']['built'])]) }}</dd>@endif</div>
        <div class="rounded-lg border border-line p-4"><dt class="text-sm text-muted">{{ __('platform::ops.health.rt.environment') }}</dt><dd class="mt-1 font-semibold">{{ $a['platform']['environment'] }}</dd></div>
        <div class="rounded-lg border border-line p-4"><dt class="text-sm text-muted">{{ __('platform::ops.health.rt.debug') }}</dt><dd @class(['mt-1 font-semibold', 'text-danger' => $a['platform']['debug'] && $a['platform']['environment'] === 'production'])>{{ $yes($a['platform']['debug']) }}</dd></div>
        <div class="rounded-lg border border-line p-4"><dt class="text-sm text-muted">{{ __('platform::ops.health.rt.maintenance') }}</dt><dd class="mt-1 font-semibold">{{ $yes($a['platform']['maintenance']) }}</dd></div>
    </dl>

    <div class="grid gap-6 lg:grid-cols-2">
        <section aria-labelledby="software" class="rounded-lg border border-line p-4">
            <h2 id="software" class="text-lg font-semibold">{{ __('platform::ops.about.software') }}</h2>
            <dl class="mt-3 divide-y divide-line text-sm">
                @foreach ([['laravel', 'Laravel '.$a['platform']['laravel']], ['php', 'PHP '.$a['platform']['php']], ['database', $a['platform']['database'] ?: '—']] as [$k, $v])
                    <div class="flex justify-between gap-4 py-2"><dt class="text-muted">{{ __('platform::ops.about.rows.'.$k) }}</dt><dd class="font-medium">{{ $v }}</dd></div>
                @endforeach
                <div class="flex justify-between gap-4 py-2"><dt class="text-muted">{{ __('platform::ops.about.rows.time') }}</dt><dd class="text-end font-medium">{{ __('platform::ops.about.time_value', ['stored' => $a['time']['stored'], 'shown' => $a['time']['shown']]) }}<span class="block text-xs text-muted">{{ Fmt::dateTime($a['time']['now']) }}</span></dd></div>
                <div class="flex justify-between gap-4 py-2"><dt class="text-muted">{{ __('platform::ops.about.rows.languages') }}</dt><dd class="font-medium">{{ $languages }}</dd></div>
            </dl>
        </section>
        <section aria-labelledby="services" class="rounded-lg border border-line p-4">
            <h2 id="services" class="text-lg font-semibold">{{ __('platform::ops.about.services') }}</h2>
            <p class="text-sm text-muted">{{ __('platform::ops.about.services_help') }}</p>
            <dl class="mt-3 divide-y divide-line text-sm">
                @foreach ($a['services'] as $k => $v)
                    <div class="flex justify-between gap-4 py-2"><dt class="text-muted">{{ __('platform::ops.about.service_names.'.$k) }}</dt>
                        <dd class="font-mono font-medium">@if ($k === 'payments')<x-platform::badge :tone="$v === 'live' ? 'success' : 'warning'">{{ __('platform::ops.about.payments.'.$v) }}</x-platform::badge>@else{{ $v ?: '—' }}@endif</dd></div>
                @endforeach
            </dl>
        </section>
    </div>

    <section aria-labelledby="modules" class="rounded-lg border border-line p-4">
        <h2 id="modules" class="text-lg font-semibold">{{ __('platform::ops.about.modules', ['n' => Fmt::number(count($a['modules']))]) }}</h2>
        <ul class="mt-3 flex flex-wrap gap-2">@foreach ($a['modules'] as $m)<li><x-platform::badge>{{ $m }}</x-platform::badge></li>@endforeach</ul>
    </section>

    <section aria-labelledby="components" class="space-y-3">
        <h2 id="components" class="text-lg font-semibold">{{ __('platform::ops.about.components') }}</h2>
        <p class="text-sm text-muted">{{ __('platform::ops.about.components_help') }}</p>
        <div class="overflow-x-auto" tabindex="0" role="region" aria-label="{{ __('platform::ops.about.components') }}">
            <table class="w-full text-sm">
                <caption class="sr-only">{{ __('platform::ops.about.components') }}</caption>
                <thead><tr class="border-b border-line">
                    <th scope="col" class="py-2 pe-3 text-start">{{ __('platform::ops.about.columns.name') }}</th>
                    <th scope="col" class="py-2 pe-3 text-start">{{ __('platform::ops.about.columns.version') }}</th>
                    <th scope="col" class="py-2 pe-3 text-start">{{ __('platform::ops.about.columns.licence') }}</th>
                    <th scope="col" class="py-2 text-start">{{ __('platform::ops.about.columns.kind') }}</th>
                </tr></thead>
                <tbody>
                @foreach ($a['components'] as $c)
                    <tr class="border-b border-line"><td class="py-2 pe-3 font-mono">{{ $c['name'] }}</td><td class="py-2 pe-3">{{ $c['version'] ?: '—' }}</td><td class="py-2 pe-3">{{ $c['licence'] ?: '—' }}</td><td class="py-2">{{ __('platform::ops.about.kinds.'.$c['kind']) }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>
</div>
@endsection
