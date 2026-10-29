@extends('layouts.admin')

@section('title', $zone ? __('shipping::admin.zones.edit_title') : __('shipping::admin.zones.new_title'))

@php
    $locale = app()->getLocale();
    $kg = fn (?int $g): string => $g === null ? '' : rtrim(rtrim(number_format($g / 1000, 3, '.', ''), '0'), '.');
    $oldRates = old('rates');
    $canManage = auth('admin')->user()?->can('shipping.manage') === true;
@endphp

@section('content')
<div class="max-w-3xl space-y-6">
    <a class="text-sm underline" href="{{ route('admin.shipping.zones.index') }}">← {{ __('shipping::admin.back') }}</a>
    <h1 class="text-2xl font-bold">{{ $zone ? __('shipping::admin.zones.edit_title') : __('shipping::admin.zones.new_title') }}</h1>
    @error('rates')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror
    @error('districts')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror

    <form method="POST" action="{{ $zone ? route('admin.shipping.zones.update', $zone) : route('admin.shipping.zones.store') }}" class="space-y-6" novalidate>
        @csrf
        @if ($zone) @method('PUT') @endif

        <x-platform::translation-fields :translations="$zone?->translations" :fields="['name' => ['label' => __('shipping::admin.zones.name'), 'type' => 'text']]" required="name" />

        <div class="space-y-1">
            <x-platform::form.checkbox name="is_active" :label="__('shipping::admin.active')" :checked="$zone?->is_active ?? true" />
            <x-platform::form.checkbox name="cod_available" :label="__('shipping::admin.zones.cod')" :checked="$zone?->cod_available ?? true" />
            <x-platform::form.checkbox name="is_fallback" :label="__('shipping::admin.zones.fallback')" :checked="$zone?->is_fallback ?? false" />
            <p class="text-sm text-muted">{{ __('shipping::admin.zones.fallback_help') }}</p>
        </div>
        <x-platform::form.field name="sort_order" inputmode="numeric" :value="$zone?->sort_order ?? 0" :label="__('shipping::admin.zones.sort')" />

        <fieldset class="space-y-3">
            <legend class="text-lg font-semibold">{{ __('shipping::admin.zones.districts') }}</legend>
            <p class="text-sm text-muted">{{ __('shipping::admin.zones.districts_help') }}</p>
            @php $chosen = collect(old('districts', $owners->where('zone_id', $zone?->id)->pluck('district_id')->all()))->map(fn ($d) => (int) $d)->all(); @endphp
            @foreach ($divisions as $division)
                <details class="rounded-lg border border-line p-3" @if (collect($districts[$division['id']] ?? [])->contains(fn ($d) => in_array($d['id'], $chosen, true))) open @endif>
                    <summary class="cursor-pointer font-medium">{{ $division['name'] }}</summary>
                    <div class="mt-2 grid gap-1 sm:grid-cols-2">
                        @foreach ($districts[$division['id']] ?? [] as $d)
                            @php $owner = $owners[$d['id']] ?? null; $elsewhere = $owner && (int) $owner->zone_id !== (int) $zone?->id; @endphp
                            <label class="flex min-h-9 items-center gap-2">
                                <input type="checkbox" name="districts[]" value="{{ $d['id'] }}" class="size-5 rounded border-line" @checked(in_array($d['id'], $chosen, true))>
                                <span>{{ $d['name'] }} @if ($elsewhere)<span class="text-sm text-muted">{{ __('shipping::admin.zones.in_zone', ['zone' => $owner->name]) }}</span>@endif</span>
                            </label>
                        @endforeach
                    </div>
                </details>
            @endforeach
        </fieldset>

        <fieldset class="space-y-4">
            <legend class="text-lg font-semibold">{{ __('shipping::admin.zones.rates') }}</legend>
            <p class="text-sm text-muted">{{ __('shipping::admin.zones.rates_help') }}</p>
            @forelse ($methods as $method)
                @php
                    $existing = collect($bands[$method->id] ?? [])->map(fn ($b) => ['weight' => $kg($b->max_weight_g), 'price' => \App\Modules\Platform\DTOs\Money::of((int) $b->price_minor)->toMajorString()])->values()->all();
                    $rows = $oldRates !== null ? array_values((array) ($oldRates[$method->public_id] ?? [])) : $existing;
                    while (count($rows) < count($existing) + 3 && count($rows) < 12) { $rows[] = ['weight' => '', 'price' => '']; }
                @endphp
                <div class="space-y-2 rounded-lg border border-line p-3">
                    <h3 class="font-medium">{{ $method->translate('name') ?? $method->code }}</h3>
                    @foreach ($rows as $i => $row)
                        <div class="grid grid-cols-2 gap-2">
                            <div><label for="rate-{{ $method->public_id }}-{{ $i }}-w" class="block text-sm">{{ __('shipping::admin.zones.weight') }} <span class="sr-only">{{ __('shipping::admin.zones.row', ['n' => $i + 1]) }}</span></label>
                                <input id="rate-{{ $method->public_id }}-{{ $i }}-w" name="rates[{{ $method->public_id }}][{{ $i }}][weight]" inputmode="decimal" value="{{ $row['weight'] ?? '' }}" class="block w-full rounded-md border border-line px-3 py-2"></div>
                            <div><label for="rate-{{ $method->public_id }}-{{ $i }}-p" class="block text-sm">{{ __('shipping::admin.zones.price') }} <span class="sr-only">{{ __('shipping::admin.zones.row', ['n' => $i + 1]) }}</span></label>
                                <input id="rate-{{ $method->public_id }}-{{ $i }}-p" name="rates[{{ $method->public_id }}][{{ $i }}][price]" inputmode="decimal" value="{{ $row['price'] ?? '' }}" class="block w-full rounded-md border border-line px-3 py-2"></div>
                        </div>
                    @endforeach
                </div>
            @empty
                <p class="text-muted">{{ __('shipping::admin.zones.no_methods') }}</p>
            @endforelse
        </fieldset>

        @if ($canManage)<x-platform::form.button>{{ __('shipping::admin.save') }}</x-platform::form.button>@endif
    </form>
</div>
@endsection
