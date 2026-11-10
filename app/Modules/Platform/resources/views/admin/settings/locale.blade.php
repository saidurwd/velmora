@extends('layouts.admin')

@section('title', __('platform::settings.locale.title'))

@section('content')
<div class="max-w-2xl space-y-6">
    <h1 class="text-2xl font-bold">{{ __('platform::settings.locale.title') }}</h1>
    <p class="text-muted">{{ __('platform::settings.locale.intro') }}</p>
    <dl class="space-y-3">
        <div><dt class="text-sm text-muted">{{ __('platform::settings.locale.currency') }}</dt><dd class="font-medium">{{ $currency }}</dd></div>
        <div><dt class="text-sm text-muted">{{ __('platform::settings.locale.default') }}</dt><dd class="font-medium">{{ $labels[$default] ?? $default }}</dd></div>
        <div><dt class="text-sm text-muted">{{ __('platform::settings.locale.languages') }}</dt><dd class="font-medium">{{ collect($supported)->map(fn ($l) => $labels[$l] ?? $l)->join(', ') }}</dd></div>
    </dl>
</div>
@endsection
