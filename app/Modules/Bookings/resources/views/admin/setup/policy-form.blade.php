@extends('layouts.admin')

@php
    $row = $p['row'] ?? null;
    $can = $can ?? true;
    $pct = fn (int $bp) => \App\Modules\Bookings\Services\Percent::fromBasisPoints($bp);
    $windows = old('windows', isset($p['windows']) ? $p['windows']->map(fn ($w) => ['hours' => $w->hours_before, 'fee' => $pct((int) $w->fee_bp)])->all() : [['hours' => 24, 'fee' => '0']]);
    $windows = array_merge($windows, [['hours' => '', 'fee' => ''], ['hours' => '', 'fee' => '']]);
@endphp

@section('title', $row ? __('bookings::setup.edit_policy') : __('bookings::setup.new_policy'))

@section('content')
<div class="max-w-3xl space-y-6">
    <h1 class="text-2xl font-bold">{{ $row ? __('bookings::setup.edit_policy') : __('bookings::setup.new_policy') }}</h1>
    @include('bookings::admin.setup._nav')
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    @error('setup')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror
    <p class="text-sm text-muted">{{ __('bookings::setup.policy_help') }}</p>
    <form method="POST" action="{{ $row ? route('admin.bookings.setup.policies.update', ['policy' => $row->public_id]) : route('admin.bookings.setup.policies.store') }}" class="space-y-5">
        @csrf @if ($row) @method('PUT') @endif
        <fieldset class="space-y-2 rounded-lg border border-line p-4"><legend class="px-1 font-semibold">{{ __('bookings::setup.windows') }}</legend>
            <p class="text-sm text-muted">{{ __('bookings::setup.windows_help') }}</p>
            @foreach ($windows as $i => $w)
            <div class="flex flex-wrap items-end gap-3">
                <div><label for="wh{{ $i }}" class="block text-sm font-medium">{{ __('bookings::setup.hours_before') }}</label><input id="wh{{ $i }}" name="windows[{{ $i }}][hours]" inputmode="numeric" maxlength="4" value="{{ $w['hours'] }}" @disabled(! $can) class="mt-1 w-28 rounded-md border border-line px-3 py-2"></div>
                <div><label for="wf{{ $i }}" class="block text-sm font-medium">{{ __('bookings::setup.fee_percent') }}</label><input id="wf{{ $i }}" name="windows[{{ $i }}][fee]" inputmode="decimal" maxlength="6" value="{{ $w['fee'] }}" @disabled(! $can) class="mt-1 w-28 rounded-md border border-line px-3 py-2"></div>
            </div>
            @endforeach
        </fieldset>
        <div class="grid gap-4 sm:grid-cols-2">
            <div><label for="late" class="block text-sm font-medium">{{ __('bookings::setup.late_fee') }}</label><input id="late" name="late" inputmode="decimal" maxlength="6" required value="{{ old('late', isset($row) ? $pct((int) $row->late_fee_bp) : '50') }}" @disabled(! $can) class="mt-1 block w-32 rounded-md border border-line px-3 py-2"></div>
            <div><label for="no_show" class="block text-sm font-medium">{{ __('bookings::setup.no_show_fee') }}</label><input id="no_show" name="no_show" inputmode="decimal" maxlength="6" required value="{{ old('no_show', isset($row) ? $pct((int) $row->no_show_fee_bp) : '100') }}" @disabled(! $can) class="mt-1 block w-32 rounded-md border border-line px-3 py-2"></div>
            <div><label for="reschedule_hours" class="block text-sm font-medium">{{ __('bookings::setup.move_hours') }}</label><input id="reschedule_hours" name="reschedule_hours" type="number" min="0" max="8760" required value="{{ old('reschedule_hours', $row->reschedule_hours ?? 24) }}" @disabled(! $can) class="mt-1 block w-32 rounded-md border border-line px-3 py-2"></div>
            <div><label for="max_reschedules" class="block text-sm font-medium">{{ __('bookings::setup.move_times') }}</label><input id="max_reschedules" name="max_reschedules" type="number" min="0" max="20" required value="{{ old('max_reschedules', $row->max_reschedules ?? 2) }}" @disabled(! $can) class="mt-1 block w-32 rounded-md border border-line px-3 py-2"></div>
        </div>
        <label class="flex items-center gap-2"><input type="hidden" name="is_default" value="0"><input type="checkbox" name="is_default" value="1" @checked(old('is_default', $row->is_default ?? false)) @disabled(! $can) class="h-5 w-5">{{ __('bookings::setup.make_default') }}</label>
        @foreach ($locales as $locale)
            @php $own = isset($p['words']) ? ($p['words'][$locale] ?? null) : null; @endphp
            <fieldset class="space-y-2 rounded-lg border border-line p-4"><legend class="px-1 font-semibold">{{ strtoupper($locale) }}</legend>
                <div><label for="t-{{ $locale }}" class="block text-sm font-medium">{{ __('bookings::setup.words_title') }}</label><input id="t-{{ $locale }}" name="words[{{ $locale }}][title]" maxlength="120" value="{{ old('words.'.$locale.'.title', $own->title ?? '') }}" @disabled(! $can) class="mt-1 block w-full rounded-md border border-line px-3 py-2"></div>
                <div><label for="x-{{ $locale }}" class="block text-sm font-medium">{{ __('bookings::setup.words_text') }}</label><textarea id="x-{{ $locale }}" name="words[{{ $locale }}][text]" rows="4" maxlength="4000" @disabled(! $can) class="mt-1 block w-full rounded-md border border-line px-3 py-2">{{ old('words.'.$locale.'.text', $own->text ?? '') }}</textarea></div>
            </fieldset>
        @endforeach
        @if ($can)<x-platform::btn type="submit">{{ __('bookings::setup.save') }}</x-platform::btn>@endif
    </form>
</div>
@endsection
