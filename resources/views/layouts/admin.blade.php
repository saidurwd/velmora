@extends('layouts.base')

@section('body')
    @php $user = auth('admin')->user(); @endphp
    @php
        $menu = app(\App\Modules\Platform\Support\AdminMenu::class)->groupedFor($user);
        $isActive = fn (array $item): bool => request()->routeIs($item['route'].'*') && collect($item['params'])->every(fn ($v, $k) => request()->route($k) === $v);
    @endphp
    {{-- Phones: the menu sits above the page; from the large breakpoint it is a fixed-width column that scrolls by itself. Each area is a native <details>: it works without JavaScript and by keyboard, and the area of the page you are on is open. --}}
    <div class="flex min-h-screen flex-col lg:flex-row">
        <nav class="w-full shrink-0 space-y-4 border-b border-line bg-surface-alt p-4 lg:sticky lg:top-0 lg:h-screen lg:w-64 lg:overflow-y-auto lg:border-b-0 lg:border-e" aria-label="{{ __('layout.admin_navigation') }}">
            <p class="font-bold text-brand-700">{{ config('app.name') }}</p>
            @if ($user && Route::has('admin.search'))
                <form method="GET" action="{{ route('admin.search') }}" role="search">
                    <label for="admin-search" class="sr-only">{{ __('platform::productivity.search.label') }}</label>
                    <input id="admin-search" name="q" type="search" maxlength="60" value="{{ request()->routeIs('admin.search') ? request('q') : '' }}" placeholder="{{ __('platform::productivity.search.go') }}" class="block w-full rounded-md border border-line bg-surface px-3 py-2 text-sm">
                </form>
            @endif
            @if ($menu['top'] !== [])
                <ul class="space-y-1">
                    @foreach ($menu['top'] as $item)
                        <li><a href="{{ route($item['route'], $item['params']) }}" @if ($isActive($item)) aria-current="page" @endif class="block rounded-md px-3 py-2 font-medium hover:bg-line {{ $isActive($item) ? 'bg-line font-semibold' : '' }}">{{ __($item['label']) }}</a></li>
                    @endforeach
                </ul>
            @endif
            <div class="space-y-1">
                @foreach ($menu['areas'] as $area)
                    <details class="group" @if (collect($area['items'])->contains($isActive)) open @endif>
                        <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between rounded-md px-3 py-2 font-semibold hover:bg-line [&::-webkit-details-marker]:hidden">
                            <span>{{ __('layout.nav.'.$area['key']) }}</span>
                            <svg class="size-4 shrink-0 transition-transform group-open:rotate-90 rtl:-scale-x-100" aria-hidden="true" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 4 6 6-6 6"/></svg>
                        </summary>
                        <ul class="mb-2 mt-1 space-y-1 border-s border-line ms-3 ps-2">
                            @foreach ($area['items'] as $item)
                                <li><a href="{{ route($item['route'], $item['params']) }}" @if ($isActive($item)) aria-current="page" @endif class="block rounded-md px-3 py-2 text-sm hover:bg-line {{ $isActive($item) ? 'bg-line font-semibold' : '' }}">{{ __($item['label']) }}</a></li>
                            @endforeach
                        </ul>
                    </details>
                @endforeach
            </div>
            <x-platform::language-switcher />
            @if ($user)
                <form method="POST" action="{{ route('admin.logout') }}">
                    @csrf
                    <button class="text-sm underline">{{ __('layout.sign_out') }} ({{ $user->name }})</button>
                </form>
            @endif
        </nav>
        <main id="main" class="min-w-0 flex-1 p-4 lg:p-6" tabindex="-1">
            @yield('content')
        </main>
    </div>
@endsection
