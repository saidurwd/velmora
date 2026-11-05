@extends('layouts.admin')

@section('title', __('notifications::admin.templates_title'))

@section('content')
<div class="max-w-5xl space-y-6">
    <h1 class="text-2xl font-bold">{{ __('notifications::admin.templates_title') }}</h1>
    <p class="text-sm text-muted">{{ __('notifications::admin.templates_help') }}</p>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    @if ($health['dead'] > 0 || $health['failing'] > 0)
        <x-platform::alert type="error">{{ __('notifications::admin.health_bad', ['dead' => $health['dead'], 'failing' => $health['failing']]) }} <a href="{{ route('admin.notifications.logs', ['status' => 'dead']) }}" class="underline">{{ __('notifications::admin.see_log') }}</a></x-platform::alert>
    @endif
    @if ($rows === [])
        <p class="text-muted">{{ __('notifications::admin.none') }}</p>
    @else
    <div class="overflow-x-auto"><table class="w-full text-left text-sm">
        <caption class="sr-only">{{ __('notifications::admin.templates_title') }}</caption>
        <thead><tr class="border-b border-line"><th scope="col" class="py-2 pr-3">{{ __('notifications::admin.message') }}</th><th scope="col" class="py-2 pr-3">{{ __('notifications::admin.channel') }}</th><th scope="col" class="py-2 pr-3">{{ __('notifications::admin.kind') }}</th><th scope="col" class="py-2 pr-3">{{ __('notifications::admin.state') }}</th></tr></thead>
        <tbody>
        @foreach ($rows as $r)
            <tr class="border-b border-line"><td class="py-2 pr-3"><a href="{{ route('admin.notifications.templates.edit', ['code' => $r['code']]) }}" class="inline-flex min-h-11 items-center underline">{{ $r['code'] }}</a></td>
                <td class="py-2 pr-3">{{ __('notifications::admin.channels.'.$r['channel']) }}</td><td class="py-2 pr-3">{{ __('notifications::admin.kinds.'.$r['kind']) }}</td>
                <td class="py-2 pr-3"><x-platform::badge :tone="$r['active'] ? 'success' : 'neutral'">{{ $r['active'] ? __('notifications::admin.on') : __('notifications::admin.off') }}</x-platform::badge></td></tr>
        @endforeach
        </tbody></table></div>
    @endif
    <p><a href="{{ route('admin.notifications.logs') }}" class="inline-flex min-h-11 items-center underline">{{ __('notifications::admin.menu.logs') }}</a></p>
</div>
@endsection
