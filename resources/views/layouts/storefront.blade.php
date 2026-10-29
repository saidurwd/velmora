@extends('layouts.base')

@php($brand = app(\App\Modules\Platform\Support\Branding::class))

@section('body')
    <header class="border-b border-line bg-surface">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-x-4 gap-y-2 px-4 py-3">
            <a href="{{ url('/'.app()->getLocale()) }}" class="flex items-center gap-3" aria-label="{{ $brand->name() }}">
                @if ($brand->logoUrl())
                    <img src="{{ $brand->logoUrl() }}" alt="" height="40" class="h-10 w-auto">
                @else
                    <span class="text-lg font-bold text-brand-700">{{ $brand->name() }}</span>
                @endif
                @if ($brand->tagline() !== '')<span class="hidden text-sm text-muted md:inline">{{ $brand->tagline() }}</span>@endif
            </a>
            <x-platform::search-box class="order-last basis-full md:order-none md:basis-auto md:flex-1" :action="url('/'.app()->getLocale().'/search')" :suggest-url="url('/'.app()->getLocale().'/search/suggest')" />
            <div class="flex items-center gap-2"><x-cart::link /><x-platform::language-switcher /></div>
        </div>
    </header>

    <main id="main" class="mx-auto max-w-6xl px-4 py-8" tabindex="-1">
        @yield('content')
    </main>

    <footer class="mt-12 border-t border-line bg-surface-alt">
        @php($pageLinks = app(\App\Modules\Platform\Support\StorefrontCache::class)->remember('footer-links', fn () => app(\App\Modules\Cms\Contracts\PageDirectory::class)->published(app()->getLocale())))
        @if ($pageLinks !== [])
            <nav aria-label="{{ __('platform::ui.info_links') }}" class="mx-auto max-w-6xl px-4 pt-6">
                <ul class="flex flex-wrap gap-x-6 gap-y-2 text-sm">
                    @foreach ($pageLinks as $link)<li><a class="hover:underline" href="{{ url('/'.app()->getLocale().'/pages/'.rawurlencode($link->slug)) }}">{{ $link->title }}</a></li>@endforeach
                </ul>
            </nav>
        @endif
        <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-2 px-4 py-6 text-sm text-muted">
            <span>&copy; {{ now()->year }} {{ $brand->name() }}. {{ __('platform::ui.footer_rights') }}</span>
            <x-platform::consent-banner />
            @if ($brand->tagline() !== '')<span>{{ $brand->tagline() }}</span>@endif
        </div>
    </footer>
@endsection
