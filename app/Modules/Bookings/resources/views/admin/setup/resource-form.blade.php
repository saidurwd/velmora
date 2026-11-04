@extends('layouts.admin')

@php
    $row = $r['row'] ?? null;
    $can = $can ?? true;
    $linked = old('services', $r['linked'] ?? []);
    $days = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];
@endphp

@section('title', $row ? $row->name : __('bookings::setup.new_resource'))

@section('content')
<div class="max-w-4xl space-y-8">
    <h1 class="text-2xl font-bold">{{ $row ? $row->name : __('bookings::setup.new_resource') }}</h1>
    @include('bookings::admin.setup._nav')
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    @error('setup')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror

    <form method="POST" action="{{ $row ? route('admin.bookings.setup.resources.update', ['resource' => $row->public_id]) : route('admin.bookings.setup.resources.store') }}" class="space-y-4">
        @csrf @if ($row) @method('PUT') @endif
        <div><label for="name" class="block text-sm font-medium">{{ __('bookings::setup.name') }}</label><input id="name" name="name" maxlength="120" required value="{{ old('name', $row->name ?? '') }}" @disabled(! $can) class="mt-1 block w-full max-w-md rounded-md border border-line px-3 py-2"></div>
        <div><label for="type" class="block text-sm font-medium">{{ __('bookings::setup.type') }}</label>
            <select id="type" name="type" @disabled(! $can) class="mt-1 block w-full max-w-xs rounded-md border border-line px-3 py-2">@foreach (['staff', 'room', 'vehicle', 'team'] as $t)<option value="{{ $t }}" @selected(old('type', $row->type ?? 'staff') === $t)>{{ __('bookings::setup.types.'.$t) }}</option>@endforeach</select></div>
        <fieldset class="space-y-2"><legend class="text-sm font-medium">{{ __('bookings::setup.does') }}</legend>
            @forelse ($services as $svc)<label class="flex items-center gap-2"><input type="checkbox" name="services[]" value="{{ $svc['sku'] }}" @checked(in_array($svc['sku'], (array) $linked, true)) @disabled(! $can) class="h-5 w-5">{{ $svc['name'] }} ({{ $svc['sku'] }})</label>
            @empty<p class="text-muted">{{ __('bookings::setup.no_services') }}</p>@endforelse</fieldset>
        <label class="flex items-center gap-2"><input type="hidden" name="active" value="0"><input type="checkbox" name="active" value="1" @checked(old('active', $row->is_active ?? true)) @disabled(! $can) class="h-5 w-5">{{ __('bookings::setup.bookable') }}</label>
        @if ($can)<x-platform::btn type="submit">{{ __('bookings::setup.save') }}</x-platform::btn>@endif
    </form>

    @if ($row)
    <section aria-labelledby="week-h" class="space-y-3">
        <h2 id="week-h" class="text-lg font-semibold">{{ __('bookings::setup.weekly') }}</h2>
        <p class="text-sm text-muted">{{ __('bookings::setup.weekly_help') }}</p>
        <form method="POST" action="{{ route('admin.bookings.setup.resources.weekly', ['resource' => $row->public_id]) }}" class="space-y-3">
            @csrf @method('PUT')
            @php $i = 0; $rules = collect($r['rules'])->map(fn ($x) => ['weekday' => (int) $x->weekday, 'start' => substr((string) $x->start_time, 0, 5), 'end' => substr((string) $x->end_time, 0, 5), 'valid_from' => $x->valid_from, 'valid_to' => $x->valid_to])->all();
                for ($k = 0; $k < 7; $k++) { $rules[] = ['weekday' => $k + 1, 'start' => '', 'end' => '', 'valid_from' => null, 'valid_to' => null]; } @endphp
            <div class="overflow-x-auto"><table class="w-full text-left text-sm">
                <caption class="sr-only">{{ __('bookings::setup.weekly') }}</caption>
                <thead><tr class="border-b border-line"><th scope="col" class="py-2 pr-3">{{ __('bookings::setup.day') }}</th><th scope="col" class="py-2 pr-3">{{ __('bookings::setup.from_time') }}</th><th scope="col" class="py-2 pr-3">{{ __('bookings::setup.to_time') }}</th><th scope="col" class="py-2 pr-3">{{ __('bookings::setup.valid_from') }}</th><th scope="col" class="py-2 pr-3">{{ __('bookings::setup.valid_to') }}</th></tr></thead>
                <tbody>
                @foreach ($rules as $rule)
                    <tr class="border-b border-line">
                        <td class="py-1 pr-3"><label class="sr-only" for="w{{ $i }}-d">{{ __('bookings::setup.day') }}</label><select id="w{{ $i }}-d" name="rules[{{ $i }}][weekday]" @disabled(! $can) class="rounded-md border border-line px-2 py-2">@foreach ($days as $n => $d)<option value="{{ $n }}" @selected($rule['weekday'] === $n)>{{ __('bookings::setup.days.'.$d) }}</option>@endforeach</select></td>
                        <td class="py-1 pr-3"><label class="sr-only" for="w{{ $i }}-s">{{ __('bookings::setup.from_time') }}</label><input id="w{{ $i }}-s" name="rules[{{ $i }}][start]" value="{{ $rule['start'] }}" placeholder="09:00" maxlength="5" @disabled(! $can) class="w-24 rounded-md border border-line px-2 py-2"></td>
                        <td class="py-1 pr-3"><label class="sr-only" for="w{{ $i }}-e">{{ __('bookings::setup.to_time') }}</label><input id="w{{ $i }}-e" name="rules[{{ $i }}][end]" value="{{ $rule['end'] }}" placeholder="17:00" maxlength="5" @disabled(! $can) class="w-24 rounded-md border border-line px-2 py-2"></td>
                        <td class="py-1 pr-3"><label class="sr-only" for="w{{ $i }}-f">{{ __('bookings::setup.valid_from') }}</label><input id="w{{ $i }}-f" type="date" name="rules[{{ $i }}][valid_from]" value="{{ $rule['valid_from'] }}" @disabled(! $can) class="rounded-md border border-line px-2 py-2"></td>
                        <td class="py-1 pr-3"><label class="sr-only" for="w{{ $i }}-t">{{ __('bookings::setup.valid_to') }}</label><input id="w{{ $i }}-t" type="date" name="rules[{{ $i }}][valid_to]" value="{{ $rule['valid_to'] }}" @disabled(! $can) class="rounded-md border border-line px-2 py-2"></td>
                    </tr>
                    @php $i++; @endphp
                @endforeach
                </tbody></table></div>
            @if ($can)<x-platform::btn type="submit">{{ __('bookings::setup.save_hours') }}</x-platform::btn>@endif
        </form>
    </section>

    <section aria-labelledby="exc-h" class="space-y-3">
        <h2 id="exc-h" class="text-lg font-semibold">{{ __('bookings::setup.exceptions') }}</h2>
        <p class="text-sm text-muted">{{ __('bookings::setup.exceptions_help') }}</p>
        @if ($r['exceptions']->isNotEmpty())
        <ul class="space-y-2 text-sm">
            @foreach ($r['exceptions'] as $e)
            <li class="flex flex-wrap items-center gap-3"><span>{{ $e->on_date }} — {{ $e->is_closed ? __('bookings::setup.closed') : substr((string) $e->start_time, 0, 5).'–'.substr((string) $e->end_time, 0, 5) }}@if ($e->note) ({{ $e->note }})@endif</span>
                @if ($can)<form method="POST" action="{{ route('admin.bookings.setup.resources.exception.remove', ['resource' => $row->public_id, 'date' => $e->on_date]) }}">@csrf @method('DELETE')<x-platform::btn type="submit" size="sm" variant="secondary">{{ __('bookings::setup.remove') }}<span class="sr-only"> {{ $e->on_date }}</span></x-platform::btn></form>@endif</li>
            @endforeach
        </ul>
        @endif
        @if ($can)
        <form method="POST" action="{{ route('admin.bookings.setup.resources.exception', ['resource' => $row->public_id]) }}" class="grid items-end gap-3 sm:grid-cols-5">
            @csrf
            <div><label for="x-date" class="block text-sm font-medium">{{ __('bookings::admin.date') }}</label><input id="x-date" type="date" name="date" required class="mt-1 block w-full rounded-md border border-line px-3 py-2"></div>
            <div><label for="x-start" class="block text-sm font-medium">{{ __('bookings::setup.from_time') }}</label><input id="x-start" name="start" placeholder="09:00" maxlength="5" class="mt-1 block w-full rounded-md border border-line px-3 py-2"></div>
            <div><label for="x-end" class="block text-sm font-medium">{{ __('bookings::setup.to_time') }}</label><input id="x-end" name="end" placeholder="13:00" maxlength="5" class="mt-1 block w-full rounded-md border border-line px-3 py-2"></div>
            <div><label for="x-note" class="block text-sm font-medium">{{ __('bookings::setup.note') }}</label><input id="x-note" name="note" maxlength="190" class="mt-1 block w-full rounded-md border border-line px-3 py-2"></div>
            <div class="space-y-2"><label class="flex items-center gap-2"><input type="checkbox" name="closed" value="1" class="h-5 w-5">{{ __('bookings::setup.closed') }}</label><x-platform::btn type="submit" size="sm">{{ __('bookings::setup.add') }}</x-platform::btn></div>
        </form>
        @endif
    </section>
    @endif
</div>
@endsection
