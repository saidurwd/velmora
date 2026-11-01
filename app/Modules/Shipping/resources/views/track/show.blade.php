@extends('layouts.storefront')

@section('title', __('shipping::tracking.title').' '.$track['number'])

@php
    $fmt = fn (int $minor, string $cur) => \App\Modules\Platform\Support\Fmt::money(\App\Modules\Platform\DTOs\Money::of($minor, $cur));
    $tone = fn (string $s) => match ($s) { 'delivered' => 'success', 'failed', 'returned', 'cancelled' => 'danger', default => 'info' };
@endphp

@section('content')
<div class="mx-auto max-w-2xl space-y-6">
    <header class="space-y-1">
        <h1 class="text-3xl font-bold">{{ __('shipping::tracking.title') }}</h1>
        <p class="text-lg">{{ __('shipping::tracking.order') }}: <strong>{{ $track['number'] }}</strong></p>
    </header>

    @if ($track['cancelled'])
        <x-platform::alert type="error"><p class="font-medium">{{ __('shipping::tracking.order_cancelled') }}</p></x-platform::alert>
    @elseif ($track['delivered'])
        <x-platform::alert type="success"><p class="font-medium">{{ __('shipping::tracking.order_delivered') }}</p></x-platform::alert>
    @elseif ($track['parcels'] === [])
        <x-platform::alert type="info"><p class="font-medium">{{ __('shipping::tracking.preparing') }}</p></x-platform::alert>
    @endif

    @foreach ($track['parcels'] as $parcel)
        <section aria-labelledby="parcel-{{ $parcel['label'] }}" class="space-y-3 rounded-lg border border-line p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="parcel-{{ $parcel['label'] }}" class="text-lg font-semibold">{{ __('shipping::tracking.parcel', ['n' => \App\Modules\Platform\Support\Fmt::number($parcel['label'])]) }}</h2>
                <x-platform::badge :tone="$tone($parcel['status'])">{{ __('shipping::tracking.status.'.$parcel['status']) }}</x-platform::badge>
            </div>

            <ul class="list-disc ps-5 text-sm">
                @foreach ($parcel['items'] as $item)<li>{{ $item['name'] }} × {{ \App\Modules\Platform\Support\Fmt::number($item['quantity']) }}</li>@endforeach
            </ul>

            <dl class="grid gap-2 text-sm sm:grid-cols-2">
                @if ($parcel['courier'])<div><dt class="text-muted">{{ __('shipping::tracking.courier') }}</dt><dd>{{ $parcel['courier'] }}</dd></div>@endif
                @if ($parcel['tracking_code'])<div><dt class="text-muted">{{ __('shipping::tracking.tracking_code') }}</dt><dd dir="ltr">{{ $parcel['tracking_code'] }}</dd></div>@endif
                @if ($parcel['cod_minor'] > 0 && $parcel['status'] !== 'delivered' && $parcel['status'] !== 'cancelled')<div><dt class="text-muted">{{ __('shipping::tracking.pay_on_delivery') }}</dt><dd>{{ $fmt($parcel['cod_minor'], $parcel['currency']) }}</dd></div>@endif
                @if ($parcel['attempts'] > 0 && $parcel['status'] !== 'delivered')<div><dt class="text-muted">{{ __('shipping::tracking.attempts') }}</dt><dd>{{ \App\Modules\Platform\Support\Fmt::number($parcel['attempts']) }}</dd></div>@endif
            </dl>
            @if ($parcel['tracking_url'])<p><a class="underline" href="{{ $parcel['tracking_url'] }}" rel="noopener noreferrer" target="_blank">{{ __('shipping::tracking.courier_page') }}<span class="sr-only"> ({{ __('shipping::tracking.opens_new_tab') }})</span></a></p>@endif

            @if ($parcel['events'] !== [])
                <h3 class="font-medium">{{ __('shipping::tracking.history') }}</h3>
                <ol class="space-y-1 border-s-2 border-line ps-4 text-sm">
                    @foreach ($parcel['events'] as $event)
                        <li><strong>{{ __('shipping::tracking.status.'.$event['status']) }}</strong> <span class="text-muted">· {{ \App\Modules\Platform\Support\Fmt::dateTime($event['at']) }}</span></li>
                    @endforeach
                </ol>
            @endif
        </section>
    @endforeach

    @if ($track['unsent'] !== [] && ! $track['cancelled'])
        <section aria-labelledby="unsent-h" class="space-y-2">
            <h2 id="unsent-h" class="text-lg font-semibold">{{ __('shipping::tracking.not_sent_yet') }}</h2>
            <ul class="list-disc ps-5 text-sm">
                @foreach ($track['unsent'] as $item)<li>{{ $item['name'] }} × {{ \App\Modules\Platform\Support\Fmt::number($item['quantity']) }}</li>@endforeach
            </ul>
        </section>
    @endif
</div>
@endsection
