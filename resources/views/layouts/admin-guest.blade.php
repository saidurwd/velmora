@extends('layouts.base')

@section('body')
    @php($brand = app(\App\Modules\Platform\Support\Branding::class)->name())
    {{-- Staff sign-in, two-step code and password pages: the brand logo, then one card on the staff canvas. --}}
    <div class="admin-ui flex min-h-screen flex-col bg-[var(--admin-canvas)]">
        <div class="motif-dark h-2 bg-night"></div>
        <div class="flex justify-end px-4 py-3 sm:px-6"><x-platform::language-switcher /></div>
        <div class="mx-auto flex w-full max-w-md flex-1 flex-col justify-center gap-8 px-4 pb-16">
            <div class="flex flex-col items-center gap-2 text-center">
                @if ($logo = app(\App\Modules\Platform\Support\Branding::class)->logo())
                    <img src="{{ $logo }}" alt="{{ $brand }}" width="335" height="100" class="h-16 w-auto sm:h-20">
                @else
                    <p class="text-2xl font-bold tracking-tight text-brand-700">{{ $brand }}</p>
                @endif
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-muted">{{ __('layout.admin_ui.panel') }}</p>
            </div>
            <main id="main" tabindex="-1" class="rounded-2xl border border-line bg-surface p-6 shadow-card sm:p-8">
                @yield('content')
            </main>
        </div>
    </div>
@endsection
