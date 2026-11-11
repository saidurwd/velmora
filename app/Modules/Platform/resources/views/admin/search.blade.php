@extends('layouts.admin')

@section('title', __('platform::productivity.search.title'))

@section('content')
<div class="max-w-3xl space-y-6">
    <h1 class="text-2xl font-bold">{{ __('platform::productivity.search.title') }}</h1>
    <form method="GET" action="{{ route('admin.search') }}" role="search" class="flex gap-2">
        <label for="q" class="sr-only">{{ __('platform::productivity.search.label') }}</label>
        <input id="q" name="q" value="{{ $term }}" maxlength="60" autofocus class="block w-full rounded-md border border-line px-3 py-2" placeholder="{{ __('platform::productivity.search.placeholder') }}">
        <x-platform::btn type="submit">{{ __('platform::productivity.search.go') }}</x-platform::btn>
    </form>

    @if ($tooShort)
        <p class="text-muted">{{ __('platform::productivity.search.too_short', ['n' => \App\Modules\Platform\Http\Controllers\Admin\SearchController::MIN]) }}</p>
    @elseif ($term !== '' && $groups === [])
        <p class="text-muted">{{ __('platform::productivity.search.none') }}</p>
    @endif

    @foreach ($groups as $group)
        <section>
            <h2 class="font-semibold">{{ __($group['kind']) }}</h2>
            <ul class="mt-2 divide-y divide-line rounded-md border border-line">
                @foreach ($group['hits'] as $hit)
                    <li><a href="{{ $hit->url }}" class="block px-4 py-3 hover:bg-surface-alt"><span class="font-medium">{{ $hit->title }}</span><span class="block text-sm text-muted">{{ $hit->detail }}</span></a></li>
                @endforeach
            </ul>
        </section>
    @endforeach
</div>
@endsection
