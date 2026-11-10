@extends('layouts.admin')

@section('title', __('platform::ops.scheduler.title'))

@section('content')
<div class="space-y-6">
    <h1 class="text-2xl font-bold">{{ __('platform::ops.scheduler.title') }}</h1>
    <x-platform::alert :type="$stale ? 'error' : 'info'">{{ $age === null ? __('platform::ops.scheduler.no_heartbeat') : __('platform::ops.scheduler.heartbeat', ['seconds' => $age]) }}@if ($stale) {{ __('platform::ops.scheduler.stale') }}@endif</x-platform::alert>
    <p class="text-sm text-muted">{{ __('platform::ops.scheduler.note') }}</p>

    <table class="w-full text-sm">
        <caption class="sr-only">{{ __('platform::ops.scheduler.title') }}</caption>
        <thead><tr class="border-b border-line">
            <th scope="col" class="py-2 text-start">{{ __('platform::ops.scheduler.columns.task') }}</th>
            <th scope="col" class="py-2 text-start">{{ __('platform::ops.scheduler.columns.schedule') }}</th>
            <th scope="col" class="py-2 text-start">{{ __('platform::ops.scheduler.columns.next') }}</th>
            <th scope="col" class="py-2 text-start">{{ __('platform::ops.scheduler.columns.last') }}</th>
            <th scope="col" class="py-2 text-start">{{ __('platform::ops.scheduler.columns.result') }}</th>
        </tr></thead>
        <tbody>
        @foreach ($tasks as $t)
            <tr class="border-b border-line align-top">
                <td class="py-2 font-mono">{{ $t['name'] }}</td>
                <td class="py-2 font-mono">{{ $t['expression'] }}</td>
                <td class="py-2">{{ \App\Modules\Platform\Support\Fmt::dateTime($t['next']) }}</td>
                <td class="py-2">{{ $t['started'] ? \App\Modules\Platform\Support\Fmt::dateTime($t['started']) : __('platform::ops.scheduler.never') }}@if ($t['runtime'] !== null) <span class="text-muted">· {{ __('platform::ops.milliseconds', ['n' => $t['runtime']]) }}</span>@endif</td>
                <td class="py-2">
                    <span class="{{ in_array($t['status'], ['failed'], true) ? 'text-danger' : '' }}">{{ $t['status'] ? __('platform::ops.scheduler.statuses.'.$t['status']) : '—' }}</span>
                    @if ($t['late'])<span class="ms-1 rounded bg-amber-100 px-1.5 py-0.5 text-xs text-warning">{{ __('platform::ops.scheduler.late') }}</span>@endif
                    @if ($t['error'])<span class="block text-xs text-muted">{{ $t['error'] }}</span>@endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endsection
