@extends('layouts.admin')

@section('title', __('platform::ops.health.title'))

@section('content')
<div class="max-w-4xl space-y-6">
    <h1 class="text-2xl font-bold">{{ __('platform::ops.health.title') }}</h1>
    @foreach ($h['warnings'] as $w)<x-platform::alert type="error">{{ __('platform::ops.health.warnings.'.$w) }}</x-platform::alert>@endforeach

    <section aria-labelledby="p-h" class="space-y-2"><h2 id="p-h" class="text-lg font-semibold">{{ __('platform::ops.health.checks') }}</h2>
        <ul class="grid gap-2 sm:grid-cols-2">
            @foreach ($h['probes'] as $name => $ok)
                <li class="flex items-center justify-between rounded-md border border-line p-3"><span>{{ __('platform::ops.health.probes.'.$name) }}</span><span class="font-medium {{ $ok ? 'text-success' : 'text-danger' }}">{{ $ok ? __('platform::ops.health.ok') : __('platform::ops.health.down') }}</span></li>
            @endforeach
            <li class="flex items-center justify-between rounded-md border border-line p-3"><span>{{ __('platform::ops.health.scheduler') }}</span>
                <span class="font-medium {{ $h['scheduler']['stale'] ? 'text-danger' : 'text-success' }}">{{ $h['scheduler']['age'] === null ? __('platform::ops.health.never') : __('platform::ops.health.seconds_ago', ['seconds' => $h['scheduler']['age']]) }}</span></li>
        </ul>
    </section>

    <section aria-labelledby="w-h" class="space-y-2"><h2 id="w-h" class="text-lg font-semibold">{{ __('platform::ops.health.work') }}</h2>
        <dl class="grid gap-3 sm:grid-cols-2">
            <div><dt class="text-sm text-muted">{{ __('platform::ops.health.failed_jobs') }}</dt><dd class="font-medium">{{ $h['failedJobs']['count'] }}@if ($h['failedJobs']['oldest']) <span class="text-sm text-muted">· {{ __('platform::ops.health.oldest') }} {{ \App\Modules\Platform\Support\Fmt::dateTime($h['failedJobs']['oldest']) }}</span>@endif @can('queues.view')<a class="ms-2 underline" href="{{ route('admin.ops.failed') }}">{{ __('platform::ops.health.open') }}</a>@endcan</dd></div>
            <div><dt class="text-sm text-muted">{{ __('platform::ops.health.outbox') }}</dt><dd class="font-medium">{{ __('platform::ops.health.outbox_line', ['pending' => $h['outbox']['pending'], 'failed' => $h['outbox']['failed']]) }}@if ($h['outbox']['oldestAge'] !== null) <span class="text-sm text-muted">· {{ __('platform::ops.health.oldest') }} {{ __('platform::ops.health.seconds_ago', ['seconds' => $h['outbox']['oldestAge']]) }}</span>@endif</dd></div>
            @foreach ($h['queues'] as $queue => $size)
                <div><dt class="text-sm text-muted">{{ __('platform::ops.health.queue', ['name' => $queue]) }}</dt><dd class="font-medium">{{ $size === null ? __('platform::ops.health.unknown') : $size }}</dd></div>
            @endforeach
        </dl>
    </section>

    <section aria-labelledby="r-h" class="space-y-2"><h2 id="r-h" class="text-lg font-semibold">{{ __('platform::ops.health.runtime') }}</h2>
        <dl class="grid gap-3 sm:grid-cols-2">
            @foreach (['environment', 'php', 'laravel', 'database', 'queue'] as $k)<div><dt class="text-sm text-muted">{{ __('platform::ops.health.rt.'.$k) }}</dt><dd class="font-medium">{{ $h['runtime'][$k] }}</dd></div>@endforeach
            <div><dt class="text-sm text-muted">{{ __('platform::ops.health.rt.debug') }}</dt><dd class="font-medium">{{ $h['runtime']['debug'] ? __('platform::ops.health.on') : __('platform::ops.health.off') }}</dd></div>
            <div><dt class="text-sm text-muted">{{ __('platform::ops.health.rt.maintenance') }}</dt><dd class="font-medium">{{ $h['runtime']['maintenance'] ? __('platform::ops.health.on') : __('platform::ops.health.off') }}</dd></div>
        </dl>
    </section>
</div>
@endsection
