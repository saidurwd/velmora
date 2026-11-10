@extends('layouts.admin')

@section('title', __('platform::audit.title'))

@section('content')
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <h1 class="text-2xl font-bold">{{ __('platform::audit.title') }}</h1>
        <a href="{{ route('admin.audit.export', array_filter($filters)) }}" class="inline-flex min-h-11 items-center rounded-md border border-line px-4 py-2 hover:bg-surface-alt">{{ __('platform::audit.export') }}</a>
    </div>
    <p class="text-sm text-muted">{{ __('platform::audit.intro', ['max' => number_format(\App\Modules\Platform\Services\AuditLogQuery::exportCap())]) }}</p>
    <x-platform::form.errors />

    <form method="GET" action="{{ route('admin.audit.index') }}" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" novalidate>
        <div><label for="from" class="block text-sm font-medium">{{ __('platform::audit.filters.from') }}</label><input id="from" name="from" type="date" value="{{ $filters['from'] }}" class="w-full rounded-md border border-line px-3 py-2"></div>
        <div><label for="to" class="block text-sm font-medium">{{ __('platform::audit.filters.to') }}</label><input id="to" name="to" type="date" value="{{ $filters['to'] }}" class="w-full rounded-md border border-line px-3 py-2"></div>
        <div><label for="actor" class="block text-sm font-medium">{{ __('platform::audit.filters.actor') }}</label><input id="actor" name="actor" value="{{ $filters['actor'] }}" class="w-full rounded-md border border-line px-3 py-2"></div>
        <div><label for="action" class="block text-sm font-medium">{{ __('platform::audit.filters.action') }}</label><input id="action" name="action" value="{{ $filters['action'] }}" placeholder="cms." class="w-full rounded-md border border-line px-3 py-2"></div>
        <div><label for="subject_type" class="block text-sm font-medium">{{ __('platform::audit.filters.subject_type') }}</label><input id="subject_type" name="subject_type" value="{{ $filters['subject_type'] }}" class="w-full rounded-md border border-line px-3 py-2"></div>
        <div><label for="subject_id" class="block text-sm font-medium">{{ __('platform::audit.filters.subject_id') }}</label><input id="subject_id" name="subject_id" value="{{ $filters['subject_id'] }}" class="w-full rounded-md border border-line px-3 py-2"></div>
        <div><label for="request_id" class="block text-sm font-medium">{{ __('platform::audit.filters.request_id') }}</label><input id="request_id" name="request_id" value="{{ $filters['request_id'] }}" class="w-full rounded-md border border-line px-3 py-2"></div>
        <div class="flex items-end gap-2"><button class="min-h-11 rounded-md bg-brand-600 px-5 py-2 font-medium text-white hover:bg-brand-700">{{ __('platform::audit.filter') }}</button><a href="{{ route('admin.audit.index') }}" class="min-h-11 rounded-md border border-line px-4 py-2">{{ __('platform::audit.clear') }}</a></div>
    </form>

    <table class="w-full text-sm">
        <caption class="sr-only">{{ __('platform::audit.title') }}</caption>
        <thead><tr class="border-b border-line">
            <th scope="col" class="py-2 text-start">{{ __('platform::audit.columns.time') }}</th>
            <th scope="col" class="py-2 text-start">{{ __('platform::audit.columns.actor') }}</th>
            <th scope="col" class="py-2 text-start">{{ __('platform::audit.columns.action') }}</th>
            <th scope="col" class="py-2 text-start">{{ __('platform::audit.columns.subject') }}</th>
            <th scope="col" class="py-2 text-start">{{ __('platform::audit.columns.ip') }}</th>
        </tr></thead>
        <tbody>
        @forelse ($page['rows'] as $r)
            <tr class="border-b border-line">
                <td class="py-2"><a class="underline" href="{{ route('admin.audit.show', $r->id) }}">{{ \App\Modules\Platform\Support\Fmt::dateTime(\Carbon\CarbonImmutable::parse($r->created_at, 'UTC')) }}</a></td>
                <td class="py-2">{{ $r->actor_label ?: '—' }} <span class="text-xs text-muted">{{ $r->actor_type }}@if ($r->actor_id) #{{ $r->actor_id }}@endif</span></td>
                <td class="py-2 font-mono">{{ $r->action }}</td>
                <td class="py-2">{{ $r->subject_type }} {{ $r->subject_id }}</td>
                <td class="py-2">{{ \App\Modules\Platform\Services\AuditLogQuery::maskIp($r->ip) }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="py-6 text-center text-muted">{{ __('platform::audit.empty') }}</td></tr>
        @endforelse
        </tbody>
    </table>
    @if ($page['next'])<a class="underline" href="{{ route('admin.audit.index', array_filter($filters) + ['before' => $page['next']]) }}">{{ __('platform::audit.older') }}</a>@endif
</div>
@endsection
