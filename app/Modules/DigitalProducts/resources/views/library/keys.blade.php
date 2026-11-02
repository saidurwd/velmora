@extends($guest ? 'layouts.storefront' : 'layouts.account')

@section('title', __('digital-products::library.keys_title'))

@section($guest ? 'content' : 'account')
<div class="{{ $guest ? 'mx-auto max-w-2xl space-y-6' : 'space-y-6' }}">
    <h1 class="text-2xl font-bold">{{ __('digital-products::library.keys_title') }}</h1>
    <p class="text-sm text-muted">{{ __('digital-products::library.keys_help') }}</p>
    <ul class="space-y-2">
        @foreach ($keys as $key)
            <li class="rounded-md border border-line bg-surface-alt p-3"><code class="break-all font-mono text-base">{{ $key }}</code></li>
        @endforeach
    </ul>
    @if ($back)<p><a class="underline" href="{{ $back }}">← {{ __('digital-products::library.back') }}</a></p>@endif
</div>
@endsection
