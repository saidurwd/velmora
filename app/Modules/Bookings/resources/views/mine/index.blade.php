@extends($guest ? 'layouts.storefront' : 'layouts.account')

@section('title', __('bookings::mine.title'))

@php
    $fmtDate = fn ($d) => \App\Modules\Platform\Support\Fmt::dateTime($d);
    $upcoming = array_values(array_filter($rows, fn ($r) => $r['upcoming']));
    $past = array_values(array_filter($rows, fn ($r) => ! $r['upcoming']));
@endphp

@section($guest ? 'content' : 'account')
<div class="{{ $guest ? 'mx-auto max-w-3xl space-y-6' : 'space-y-6' }}">
    <h1 class="text-2xl font-bold">{{ __('bookings::mine.title') }}@if ($guest && isset($number)) <span class="text-base font-normal text-muted">— {{ $number }}</span>@endif</h1>
    @if ($guest && session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    @if ($rows === [])
        <p class="text-muted">{{ __('bookings::mine.empty') }}</p>
    @else
    @foreach ([['upcoming', $upcoming], ['past', $past]] as [$key, $group])
        @if ($group !== [])
        <section aria-labelledby="{{ $key }}-h" class="space-y-3">
            <h2 id="{{ $key }}-h" class="text-lg font-semibold">{{ __('bookings::mine.'.$key) }}</h2>
            <ul class="space-y-3">
            @foreach ($group as $r)
                <li class="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-line p-4">
                    <div>
                        <h3 class="font-semibold">{{ $r['service'] }}</h3>
                        <p class="text-sm">{{ $fmtDate($r['starts_at']) }}</p>
                        <p class="text-sm text-muted">{{ __('bookings::mine.order') }} {{ $r['order_number'] }} · {{ \App\Modules\Platform\Support\Fmt::money($r['paid']) }}</p>
                    </div>
                    <div class="flex flex-col items-end gap-2">
                        <x-platform::badge :tone="in_array($r['status'], ['confirmed', 'in_progress', 'completed'], true) ? 'success' : ($r['status'] === 'held' ? 'neutral' : 'danger')">{{ __('bookings::mine.status.'.$r['status']) }}</x-platform::badge>
                        <a href="{{ route($showRoute, ['locale' => app()->getLocale(), 'booking' => $r['public_id']] + $showParams) }}" class="inline-flex min-h-11 items-center rounded-md border border-line px-4 py-2 text-sm hover:bg-surface-alt">{{ __('bookings::mine.open') }}<span class="sr-only"> {{ $r['service'] }}</span></a>
                    </div>
                </li>
            @endforeach
            </ul>
        </section>
        @endif
    @endforeach
    @endif
</div>
@endsection
