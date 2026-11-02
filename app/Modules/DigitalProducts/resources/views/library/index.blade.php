@extends($guest ? 'layouts.storefront' : 'layouts.account')

@section('title', __('digital-products::library.title'))

@php $fmtDate = fn ($d) => \App\Modules\Platform\Support\Fmt::dateTime($d); @endphp

@section($guest ? 'content' : 'account')
<div class="{{ $guest ? 'mx-auto max-w-3xl space-y-6' : 'space-y-6' }}">
    <h1 class="text-2xl font-bold">{{ __('digital-products::library.title') }}@if ($guest && isset($number)) <span class="text-base font-normal text-muted">— {{ $number }}</span>@endif</h1>
    @error('download')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror
    @if ($rows === [])
        <p class="text-muted">{{ __('digital-products::library.empty') }}</p>
    @else
    <ul class="space-y-4">
        @foreach ($rows as $r)
        <li class="space-y-2 rounded-lg border border-line p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div><h2 class="font-semibold">{{ $r['product'] }}</h2>
                    <p class="text-sm text-muted">{{ __('digital-products::library.order') }} {{ $r['order'] }}@if ($r['file']) · {{ $r['file'] }} · {{ __('digital-products::library.version', ['n' => $r['version']]) }}@endif</p></div>
                <x-platform::badge :tone="$r['state'] === 'available' ? 'success' : ($r['state'] === 'not_ready' ? 'neutral' : 'danger')">{{ __('digital-products::library.state.'.$r['state']) }}</x-platform::badge>
            </div>
            <p class="text-sm">{{ $r['unlimited'] ? __('digital-products::library.unlimited') : __('digital-products::library.left', ['n' => $r['left']]) }}@if ($r['expires_at']) · {{ __('digital-products::library.until', ['date' => $fmtDate($r['expires_at'])]) }}@endif</p>
            @if ($r['state'] === 'available')
            <form method="POST" action="{{ $r['action'] }}">@csrf<x-platform::btn type="submit" size="sm">{{ __('digital-products::library.download') }}<span class="sr-only"> {{ $r['product'] }}</span></x-platform::btn></form>
            @elseif ($r['state'] === 'not_ready')
            <p class="text-sm text-muted">{{ __('digital-products::library.not_ready') }}</p>
            @endif
        </li>
        @endforeach
    </ul>
    @endif
</div>
@endsection
