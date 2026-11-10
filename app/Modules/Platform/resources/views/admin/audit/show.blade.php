@extends('layouts.admin')

@section('title', __('platform::audit.title'))

@section('content')
<div class="max-w-4xl space-y-6">
    <a class="underline" href="{{ route('admin.audit.index') }}">← {{ __('platform::audit.title') }}</a>
    <h1 class="font-mono text-2xl font-bold">{{ $row->action }}</h1>
    <dl class="grid gap-3 sm:grid-cols-2">
        <div><dt class="text-sm text-muted">{{ __('platform::audit.columns.time') }}</dt><dd>{{ \App\Modules\Platform\Support\Fmt::dateTime(\Carbon\CarbonImmutable::parse($row->created_at, 'UTC')) }}</dd></div>
        <div><dt class="text-sm text-muted">{{ __('platform::audit.columns.actor') }}</dt><dd>{{ $row->actor_label ?: '—' }} ({{ $row->actor_type }}@if ($row->actor_id) #{{ $row->actor_id }}@endif)</dd></div>
        <div><dt class="text-sm text-muted">{{ __('platform::audit.columns.subject') }}</dt><dd>{{ $row->subject_type }} {{ $row->subject_id }}</dd></div>
        <div><dt class="text-sm text-muted">{{ __('platform::audit.columns.ip') }}</dt><dd>{{ $ip }}</dd></div>
        <div><dt class="text-sm text-muted">{{ __('platform::audit.filters.request_id') }}</dt><dd class="font-mono">{{ $row->request_id }}</dd></div>
    </dl>
    <p class="text-sm text-muted">{{ __('platform::audit.redacted') }}</p>
    <div class="grid gap-4 md:grid-cols-2">
        <section><h2 class="font-semibold">{{ __('platform::audit.before') }}</h2><pre class="overflow-x-auto rounded-md border border-line bg-surface-alt p-3 text-sm">{{ $before === [] ? '—' : json_encode($before, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre></section>
        <section><h2 class="font-semibold">{{ __('platform::audit.after') }}</h2><pre class="overflow-x-auto rounded-md border border-line bg-surface-alt p-3 text-sm">{{ $after === [] ? '—' : json_encode($after, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre></section>
    </div>
</div>
@endsection
