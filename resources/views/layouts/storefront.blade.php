@extends('layouts.base')

@php($brand = app(\App\Modules\Platform\Support\Branding::class))

@section('body')
    <header class="border-b border-line bg-surface">
        <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3">
            <a href="{{ url('/'.app()->getLocale()) }}" class="flex items-center gap-3" aria-label="{{ $brand->name() }}">
                @if ($brand->logoUrl())
                    <img src="{{ $brand->logoUrl() }}" alt="" height="40" class="h-10 w-auto">
                @else
                    <span class="text-lg font-bold text-brand-700">{{ $brand->name() }}</span>
                @endif
                @if ($brand->tagline() !== '')<span class="hidden text-sm text-muted md:inline">{{ $brand->tagline() }}</span>@endif
            </a>
            @stack('header-search')
            <x-platform::language-switcher />
        </div>
    </header>

    <main id="main" class="mx-auto max-w-6xl px-4 py-8" tabindex="-1">
        @yield('content')
    </main>

    <footer class="mt-12 border-t border-line bg-surface-alt">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-2 px-4 py-6 text-sm text-muted">
            <span>&copy; {{ now()->year }} {{ $brand->name() }}. {{ __('platform::ui.footer_rights') }}</span>
            @if ($brand->tagline() !== '')<span>{{ $brand->tagline() }}</span>@endif
        </div>
    </footer>
@endsection
