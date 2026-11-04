@extends('layouts.admin')

@section('title', __('bookings::setup.tab.policies'))

@php $pct = fn (int $bp) => \App\Modules\Bookings\Services\Percent::fromBasisPoints($bp); @endphp

@section('content')
<div class="max-w-4xl space-y-6">
    <h1 class="text-2xl font-bold">{{ __('bookings::setup.title') }}</h1>
    @include('bookings::admin.setup._nav')
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    @if (auth('admin')->user()?->can('bookings.manage'))<p><a href="{{ route('admin.bookings.setup.policies.create') }}" class="inline-flex min-h-11 items-center underline">{{ __('bookings::setup.new_policy') }}</a></p>@endif
    @if ($rows === [])
        <p class="text-muted">{{ __('bookings::setup.no_policies') }}</p>
    @else
    <div class="overflow-x-auto"><table class="w-full text-left text-sm">
        <caption class="sr-only">{{ __('bookings::setup.tab.policies') }}</caption>
        <thead><tr class="border-b border-line"><th scope="col" class="py-2 pr-3">{{ __('bookings::setup.policy') }}</th><th scope="col" class="py-2 pr-3">{{ __('bookings::setup.late_fee') }}</th><th scope="col" class="py-2 pr-3">{{ __('bookings::setup.no_show_fee') }}</th><th scope="col" class="py-2 pr-3">{{ __('bookings::setup.used_by') }}</th></tr></thead>
        <tbody>
        @foreach ($rows as $p)
            <tr class="border-b border-line"><td class="py-2 pr-3"><a href="{{ route('admin.bookings.setup.policies.edit', ['policy' => $p['public_id']]) }}" class="inline-flex min-h-11 items-center underline">{{ $p['title'] }}</a>@if ($p['default']) <x-platform::badge tone="brand">{{ __('bookings::setup.the_default') }}</x-platform::badge>@endif</td>
                <td class="py-2 pr-3">{{ $pct($p['late']) }}%</td><td class="py-2 pr-3">{{ $pct($p['no_show']) }}%</td><td class="py-2 pr-3">{{ $p['used_by'] }}</td></tr>
        @endforeach
        </tbody></table></div>
    @endif
</div>
@endsection
