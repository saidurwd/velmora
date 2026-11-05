@extends('layouts.account')

@section('title', __('engagement::wishlist.title'))

@section('account')
@php $dateOf = fn ($d) => \App\Modules\Platform\Support\Fmt::date(\Carbon\CarbonImmutable::parse($d, 'UTC')); @endphp
<div class="space-y-8">
    <div class="space-y-2">
        <h1 class="text-2xl font-bold">{{ __('engagement::wishlist.title') }}</h1>
        <p>{{ __('engagement::wishlist.intro') }}</p>
    </div>
    @error('wishlist')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror

    @if ($items === [])
        <p class="text-muted">{{ __('engagement::wishlist.empty') }}</p>
    @else
        <ul class="space-y-3">
            @foreach ($items as $item)
                <li class="flex flex-wrap items-center gap-4 rounded-lg border border-line p-3">
                    @if ($item['image'])<img src="{{ $item['image'] }}" alt="" class="size-16 rounded-md object-cover" width="64" height="64" loading="lazy">@endif
                    <div class="min-w-0 flex-1">
                        <a class="font-medium underline" href="{{ $item['url'] }}">{{ $item['name'] }}</a>
                        <div class="text-sm text-muted">
                            @if ($item['price'])<span>{{ $item['price'] }}</span> · @endif
                            <x-platform::badge :tone="$item['state'] === 'in_stock' ? 'success' : 'neutral'">{{ __('engagement::wishlist.state.'.$item['state']) }}</x-platform::badge>
                            @if ($item['alert'])<span class="ms-2">{{ __('engagement::wishlist.alert_on') }}</span>@endif
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($item['state'] === 'in_stock')
                            <form method="POST" action="{{ route('account.wishlist.cart', $item['variant']) }}">@csrf
                                <x-platform::btn type="submit" size="sm">{{ __('engagement::wishlist.to_cart') }}<span class="sr-only"> — {{ $item['name'] }}</span></x-platform::btn>
                            </form>
                        @elseif ($item['canAlert'] && ! $item['alert'])
                            <form method="POST" action="{{ route('account.alerts.add') }}">@csrf
                                <input type="hidden" name="variant" value="{{ $item['variant'] }}">
                                <x-platform::btn type="submit" size="sm">{{ __('engagement::wishlist.alert_button') }}<span class="sr-only"> — {{ $item['name'] }}</span></x-platform::btn>
                            </form>
                        @endif
                        <form method="POST" action="{{ route('account.wishlist.remove', $item['variant']) }}">@csrf @method('DELETE')
                            <x-platform::btn type="submit" size="sm" variant="secondary">{{ __('engagement::wishlist.remove') }}<span class="sr-only"> — {{ $item['name'] }}</span></x-platform::btn>
                        </form>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    <section class="space-y-3 border-t border-line pt-6" aria-labelledby="alerts-h">
        <h2 id="alerts-h" class="text-lg font-semibold">{{ __('engagement::wishlist.alerts_title') }}</h2>
        <p class="text-sm text-muted">{{ __('engagement::wishlist.alerts_help') }}</p>
        @if ($alerts === [])
            <p class="text-muted">{{ __('engagement::wishlist.alerts_none') }}</p>
        @else
            <ul class="space-y-2">
                @foreach ($alerts as $alert)
                    <li class="flex flex-wrap items-center justify-between gap-3">
                        <span><a class="underline" href="{{ $alert['url'] }}">{{ $alert['name'] }}</a> <span class="text-sm text-muted">· {{ __('engagement::wishlist.since', ['date' => $dateOf($alert['since'])]) }}</span></span>
                        <form method="POST" action="{{ route('account.alerts.remove', $alert['variant']) }}">@csrf @method('DELETE')
                            <x-platform::btn type="submit" size="sm" variant="secondary">{{ __('engagement::wishlist.alert_cancel') }}<span class="sr-only"> — {{ $alert['name'] }}</span></x-platform::btn>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
@endsection
