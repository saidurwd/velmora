@extends('layouts.base')

@section('body')
    <div class="mx-auto flex min-h-screen max-w-md flex-col justify-center gap-6 px-4 py-10">
        <div class="flex items-center justify-between">
            <p class="text-lg font-bold text-brand-700">{{ config('app.name') }}</p>
            <x-platform::language-switcher />
        </div>
        <main id="main" tabindex="-1">
            @yield('content')
        </main>
    </div>
@endsection
