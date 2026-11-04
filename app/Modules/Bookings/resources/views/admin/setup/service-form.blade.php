@extends('layouts.admin')

@section('title', $s['name'])

@php
    $sv = $s['service'];
    $pk = $s['package'];
    $val = fn (string $key, $default) => old($key, $default);
@endphp

@section('content')
<div class="max-w-3xl space-y-6">
    <h1 class="text-2xl font-bold">{{ $s['name'] }} <span class="text-base font-normal text-muted">({{ $s['sku'] }})</span></h1>
    @include('bookings::admin.setup._nav')
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    @error('setup')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror
    @if ($sv === null)<p class="text-muted">{{ __('bookings::setup.not_yet') }}</p>@endif
    <form method="POST" action="{{ route('admin.bookings.setup.services.save', ['sku' => $s['sku']]) }}" class="space-y-5">
        @csrf @method('PUT')
        <div><label for="location" class="block text-sm font-medium">{{ __('bookings::setup.where') }}</label>
            <select id="location" name="location" @disabled(! $can) class="mt-1 block w-full max-w-xs rounded-md border border-line px-3 py-2">@foreach (['at_provider', 'online'] as $l)<option value="{{ $l }}" @selected($val('location', $sv->location_type ?? 'at_provider') === $l)>{{ __('bookings::book.location.'.$l) }}</option>@endforeach</select></div>
        <div class="grid gap-4 sm:grid-cols-3">
            <div><label for="duration_min" class="block text-sm font-medium">{{ __('bookings::setup.duration_min') }}</label><input id="duration_min" name="duration_min" type="number" min="5" max="1440" value="{{ $val('duration_min', $pk->duration_min ?? 60) }}" @disabled(! $can) class="mt-1 block w-full rounded-md border border-line px-3 py-2"></div>
            <div><label for="buffer_before_min" class="block text-sm font-medium">{{ __('bookings::setup.buffer_before') }}</label><input id="buffer_before_min" name="buffer_before_min" type="number" min="0" max="240" value="{{ $val('buffer_before_min', $pk->buffer_before_min ?? 0) }}" @disabled(! $can) class="mt-1 block w-full rounded-md border border-line px-3 py-2"></div>
            <div><label for="buffer_after_min" class="block text-sm font-medium">{{ __('bookings::setup.buffer_after') }}</label><input id="buffer_after_min" name="buffer_after_min" type="number" min="0" max="240" value="{{ $val('buffer_after_min', $pk->buffer_after_min ?? 0) }}" @disabled(! $can) class="mt-1 block w-full rounded-md border border-line px-3 py-2"></div>
        </div>
        <div class="grid gap-4 sm:grid-cols-3">
            <div><label for="lead_time_min" class="block text-sm font-medium">{{ __('bookings::setup.lead_time') }}</label><input id="lead_time_min" name="lead_time_min" type="number" min="0" max="525600" value="{{ $val('lead_time_min', $sv->lead_time_min ?? 60) }}" @disabled(! $can) class="mt-1 block w-full rounded-md border border-line px-3 py-2"></div>
            <div><label for="max_horizon_days" class="block text-sm font-medium">{{ __('bookings::setup.horizon') }}</label><input id="max_horizon_days" name="max_horizon_days" type="number" min="1" max="365" value="{{ $val('max_horizon_days', $sv->max_horizon_days ?? 60) }}" @disabled(! $can) class="mt-1 block w-full rounded-md border border-line px-3 py-2"></div>
            <div><label for="hold_minutes" class="block text-sm font-medium">{{ __('bookings::setup.hold') }}</label><input id="hold_minutes" name="hold_minutes" type="number" min="5" max="120" value="{{ $val('hold_minutes', $sv->hold_minutes ?? 15) }}" @disabled(! $can) class="mt-1 block w-full rounded-md border border-line px-3 py-2"></div>
        </div>
        <div><label for="policy" class="block text-sm font-medium">{{ __('bookings::setup.policy') }}</label>
            <select id="policy" name="policy" @disabled(! $can) class="mt-1 block w-full max-w-md rounded-md border border-line px-3 py-2"><option value="">{{ __('bookings::setup.default_policy') }}</option>
                @foreach ($s['policies'] as $p)<option value="{{ $p['public_id'] }}" @selected($val('policy', $s['policy_public_id']) === $p['public_id'])>{{ $p['title'] }}@if ($p['default']) ({{ __('bookings::setup.the_default') }})@endif</option>@endforeach</select></div>
        <div><label for="meeting_url" class="block text-sm font-medium">{{ __('bookings::setup.meeting_url') }}</label><p class="text-sm text-muted">{{ __('bookings::setup.meeting_help') }}</p>
            <input id="meeting_url" name="meeting_url" type="url" maxlength="500" value="{{ $val('meeting_url', $sv->meeting_url ?? '') }}" placeholder="https://" @disabled(! $can) class="mt-1 block w-full rounded-md border border-line px-3 py-2"></div>
        <div class="space-y-2">
            <label class="flex items-center gap-2"><input type="hidden" name="active" value="0"><input type="checkbox" name="active" value="1" @checked($val('active', $sv->is_active ?? true)) @disabled(! $can) class="h-5 w-5">{{ __('bookings::setup.bookable') }}</label>
            <label class="flex items-center gap-2"><input type="hidden" name="package_active" value="0"><input type="checkbox" name="package_active" value="1" @checked($val('package_active', $pk->is_active ?? true)) @disabled(! $can) class="h-5 w-5">{{ __('bookings::setup.package_on') }}</label>
        </div>
        @if ($can)<x-platform::btn type="submit">{{ __('bookings::setup.save') }}</x-platform::btn>@endif
    </form>
</div>
@endsection
