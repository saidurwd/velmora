@extends('layouts.base')

@section('body')
    <header class="border-b border-line">
        <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-3">
            <a href="{{ url('/'.app()->getLocale()) }}" class="text-lg font-bold text-brand-700">{{ config('app.name') }}</a>
            <x-platform::language-switcher />
        </div>
    </header>

    <main id="main" class="mx-auto max-w-6xl px-4 py-8" tabindex="-1">
        @yield('content')
    </main>

    <footer class="border-t border-line bg-surface-alt">
        <div class="mx-auto max-w-6xl px-4 py-6 text-sm text-muted">
            &copy; {{ now()->year }} {{ config('app.name') }}
        </div>
    </footer>
@endsection
