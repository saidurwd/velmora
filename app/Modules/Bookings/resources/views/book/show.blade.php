@extends('layouts.storefront')

@section('title', __('bookings::book.title', ['name' => $variant->productName]))

@php
    $fmt = fn (\App\Modules\Platform\DTOs\Money $m) => \App\Modules\Platform\Support\Fmt::money($m);
@endphp

@section('content')
<div class="mx-auto max-w-3xl space-y-6">
    <header class="space-y-1">
        <h1 class="text-3xl font-bold">{{ $variant->productName }}</h1>
        <p class="text-muted">{{ __('bookings::book.duration', ['minutes' => $package->duration_min]) }} · {{ __('bookings::book.location.'.$service->location_type) }}@if ($price) · <strong>{{ $fmt($price) }}</strong>@endif</p>
        <p class="text-sm text-muted">{{ __('bookings::book.help', ['minutes' => $service->hold_minutes]) }}</p>
    </header>

    @error('slot')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror

    <form method="GET" action="{{ route('bookings.book', ['locale' => app()->getLocale(), 'sku' => $variant->sku]) }}" class="flex flex-wrap items-end gap-3">
        <div><label for="date" class="block text-sm font-medium">{{ __('bookings::book.day') }}</label>
            <input id="date" type="date" name="date" value="{{ $date }}" min="{{ $min }}" max="{{ $max }}" class="mt-1 rounded-md border border-line px-3 py-2"></div>
        <x-platform::btn type="submit" variant="secondary">{{ __('bookings::book.show_times') }}</x-platform::btn>
    </form>

    <section aria-labelledby="times-h" class="space-y-3">
        <h2 id="times-h" class="text-lg font-semibold">{{ __('bookings::book.times_on', ['date' => \Carbon\CarbonImmutable::parse($date)->locale(app()->getLocale())->isoFormat('LL')]) }}</h2>
        @if ($problem)<p class="text-danger" role="alert">{{ __('bookings::errors.date_invalid') }}</p>@endif
        @if ($slots === [])
            <p class="text-muted">{{ __('bookings::book.no_times') }}</p>
        @else
            <ul class="flex flex-wrap gap-2">
            @foreach ($slots as $slot)
                <li>
                    <form method="POST" action="{{ route('bookings.hold', ['locale' => app()->getLocale(), 'sku' => $variant->sku]) }}">@csrf
                        <input type="hidden" name="resource" value="{{ $slot->resourcePublicId }}"><input type="hidden" name="start" value="{{ $slot->startsAt->format('Y-m-d H:i:s') }}">
                        <button type="submit" class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-md border border-line px-4 py-2 hover:bg-surface-alt">{{ $slot->localStart()->locale(app()->getLocale())->isoFormat('LT') }}<span class="sr-only"> — {{ $slot->resourceName }}</span></button>
                    </form>
                </li>
            @endforeach
            </ul>
            <p class="text-sm text-muted">{{ __('bookings::book.times_note', ['zone' => $zone]) }}</p>
        @endif
    </section>
</div>
@endsection
