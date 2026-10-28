@extends('layouts.base')

@section('body')
    <div class="flex min-h-screen">
        <nav class="w-60 shrink-0 border-e border-line bg-surface-alt p-4" aria-label="{{ __('layout.admin_navigation') }}">
            <p class="mb-4 font-bold text-brand-700">{{ config('app.name') }}</p>
            <x-platform::language-switcher />
        </nav>
        <main id="main" class="flex-1 p-6" tabindex="-1">
            @yield('content')
        </main>
    </div>
@endsection
