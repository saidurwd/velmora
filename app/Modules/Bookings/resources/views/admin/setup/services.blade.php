@extends('layouts.admin')

@section('title', __('bookings::setup.tab.services'))

@section('content')
<div class="max-w-5xl space-y-6">
    <h1 class="text-2xl font-bold">{{ __('bookings::setup.title') }}</h1>
    @include('bookings::admin.setup._nav')
    <p class="text-sm text-muted">{{ __('bookings::setup.services_help') }}</p>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    <form method="GET" action="" onsubmit="event.preventDefault(); var v = this.sku.value.trim(); if (v) { window.location = '{{ route('admin.bookings.setup.services') }}/' + encodeURIComponent(v); }" class="flex items-end gap-3">
        <div class="flex-1"><label for="sku" class="block text-sm font-medium">{{ __('bookings::setup.add_by_sku') }}</label><input id="sku" name="sku" maxlength="64" class="mt-1 block w-full max-w-xs rounded-md border border-line px-3 py-2"></div>
        <x-platform::btn type="submit" size="sm">{{ __('bookings::setup.open') }}</x-platform::btn>
    </form>
    @if ($rows === [])
        <p class="text-muted">{{ __('bookings::setup.no_services') }}</p>
    @else
    <div class="overflow-x-auto"><table class="w-full text-left text-sm">
        <caption class="sr-only">{{ __('bookings::setup.tab.services') }}</caption>
        <thead><tr class="border-b border-line"><th scope="col" class="py-2 pr-3">{{ __('bookings::setup.service') }}</th><th scope="col" class="py-2 pr-3">{{ __('bookings::setup.where') }}</th><th scope="col" class="py-2 pr-3">{{ __('bookings::setup.duration') }}</th><th scope="col" class="py-2 pr-3">{{ __('bookings::setup.policy') }}</th><th scope="col" class="py-2 pr-3">{{ __('bookings::setup.state') }}</th></tr></thead>
        <tbody>
        @foreach ($rows as $r)
            <tr class="border-b border-line"><td class="py-2 pr-3"><a href="{{ route('admin.bookings.setup.services.edit', ['sku' => $r['sku']]) }}" class="inline-flex min-h-11 items-center underline">{{ $r['name'] }} ({{ $r['sku'] }})</a></td>
                <td class="py-2 pr-3">{{ __('bookings::book.location.'.$r['location']) }}</td><td class="py-2 pr-3">{{ $r['duration'] === null ? '—' : __('bookings::book.duration', ['minutes' => $r['duration']]) }}</td>
                <td class="py-2 pr-3">{{ $r['policy'] ?? __('bookings::setup.default_policy') }}</td>
                <td class="py-2 pr-3"><x-platform::badge :tone="$r['active'] ? 'success' : 'neutral'">{{ $r['active'] ? __('bookings::setup.on') : __('bookings::setup.off') }}</x-platform::badge></td></tr>
        @endforeach
        </tbody></table></div>
    @endif
</div>
@endsection
