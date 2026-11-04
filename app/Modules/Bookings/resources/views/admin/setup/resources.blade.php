@extends('layouts.admin')

@section('title', __('bookings::setup.tab.resources'))

@section('content')
<div class="max-w-5xl space-y-6">
    <h1 class="text-2xl font-bold">{{ __('bookings::setup.title') }}</h1>
    @include('bookings::admin.setup._nav')
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    @if (auth('admin')->user()?->can('bookings.manage'))<p><a href="{{ route('admin.bookings.setup.resources.create') }}" class="inline-flex min-h-11 items-center underline">{{ __('bookings::setup.new_resource') }}</a></p>@endif
    @if ($rows === [])
        <p class="text-muted">{{ __('bookings::setup.no_resources') }}</p>
    @else
    <div class="overflow-x-auto"><table class="w-full text-left text-sm">
        <caption class="sr-only">{{ __('bookings::setup.tab.resources') }}</caption>
        <thead><tr class="border-b border-line"><th scope="col" class="py-2 pr-3">{{ __('bookings::setup.name') }}</th><th scope="col" class="py-2 pr-3">{{ __('bookings::setup.type') }}</th><th scope="col" class="py-2 pr-3">{{ __('bookings::setup.does') }}</th><th scope="col" class="py-2 pr-3">{{ __('bookings::setup.state') }}</th></tr></thead>
        <tbody>
        @foreach ($rows as $r)
            <tr class="border-b border-line"><td class="py-2 pr-3"><a href="{{ route('admin.bookings.setup.resources.edit', ['resource' => $r['public_id']]) }}" class="inline-flex min-h-11 items-center underline">{{ $r['name'] }}</a></td>
                <td class="py-2 pr-3">{{ __('bookings::setup.types.'.$r['type']) }}</td><td class="py-2 pr-3">{{ implode(', ', $r['services']) ?: '—' }}</td>
                <td class="py-2 pr-3"><x-platform::badge :tone="$r['active'] ? 'success' : 'neutral'">{{ $r['active'] ? __('bookings::setup.on') : __('bookings::setup.off') }}</x-platform::badge></td></tr>
        @endforeach
        </tbody></table></div>
    @endif
</div>
@endsection
