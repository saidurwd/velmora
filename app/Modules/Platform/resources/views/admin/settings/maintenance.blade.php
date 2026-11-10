@extends('layouts.admin')

@section('title', __('platform::settings.maintenance.title'))

@section('content')
<div class="max-w-2xl space-y-6">
    <h1 class="text-2xl font-bold">{{ __('platform::settings.maintenance.title') }}</h1>
    <p class="text-muted">{{ __('platform::settings.maintenance.intro') }}</p>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    @if (session('bypass_url'))
        <x-platform::alert type="info">
            <p class="font-medium">{{ __('platform::settings.maintenance.bypass') }}</p>
            <p class="break-all font-mono text-sm">{{ session('bypass_url') }}</p>
            <p class="text-sm">{{ __('platform::settings.maintenance.bypass_once') }}</p>
        </x-platform::alert>
    @endif
    <x-platform::form.errors />

    <p class="text-lg">{{ __('platform::settings.maintenance.state') }}: <strong>{{ $down ? __('platform::settings.maintenance.is_down') : __('platform::settings.maintenance.is_up') }}</strong></p>

    @can('settings.manage')
        <form method="POST" action="{{ route($down ? 'admin.maintenance.disable' : 'admin.maintenance.enable') }}" class="space-y-4" novalidate>
            @csrf
            <x-platform::form.field name="password" type="password" :label="__('platform::settings.maintenance.password')" :help="__('platform::settings.maintenance.password_help')" autocomplete="current-password" required />
            <x-platform::form.button>{{ $down ? __('platform::settings.maintenance.turn_off') : __('platform::settings.maintenance.turn_on') }}</x-platform::form.button>
        </form>
    @endcan
</div>
@endsection
