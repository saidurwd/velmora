@extends('layouts.base')

@section('body')
    @php($brand = app(\App\Modules\Platform\Support\Branding::class)->name())
    {{-- Staff sign-in, two-step code and password pages: one card on the staff canvas. --}}
    <div class="admin-ui flex min-h-screen flex-col bg-[var(--admin-canvas)]">
        <div class="motif-dark h-2 bg-night"></div>
        <div class="mx-auto flex w-full max-w-md flex-1 flex-col justify-center gap-6 px-4 py-10">
            <div class="flex items-center justify-between gap-4">
                <p class="flex items-center gap-3">
                    <span aria-hidden="true" class="flex size-10 items-center justify-center rounded-lg bg-brand-600 text-lg font-bold text-white shadow-sm">{{ mb_strtoupper(mb_substr($brand, 0, 1)) }}</span>
                    <span>
                        <span class="block font-semibold leading-tight">{{ $brand }}</span>
                        <span class="block text-xs leading-tight text-muted">{{ __('layout.admin_ui.panel') }}</span>
                    </span>
                </p>
                <x-platform::language-switcher />
            </div>
            <main id="main" tabindex="-1" class="rounded-2xl border border-line bg-surface p-6 shadow-card sm:p-8">
                @yield('content')
            </main>
        </div>
    </div>
@endsection
