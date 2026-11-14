@extends('layouts.base')

@section('body')
    @php
        $user = auth('admin')->user();
        $menu = app(\App\Modules\Platform\Support\AdminMenu::class)->groupedFor($user);
        $isActive = fn (array $item): bool => request()->routeIs($item['route'].'*') && collect($item['params'])->every(fn ($v, $k) => request()->route($k) === $v);
        $brand = app(\App\Modules\Platform\Support\Branding::class)->name();
        $initials = $user ? mb_strtoupper(collect(preg_split('/\s+/u', trim((string) $user->name)) ?: [])->filter()->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('')) : '';
        $link = 'flex items-center gap-3 rounded-lg px-3 text-sm transition-colors focus-visible:outline-accent-400';
    @endphp
    {{--
        The staff area. Large screens: a dark menu column that scrolls by itself, a top bar (search, shop link, language, account) and the page on a calm canvas.
        Phones, with JavaScript: the menu is a drawer opened from the top bar (Escape or the backdrop closes it). Without JavaScript the menu simply sits above the
        page, and every area is a native <details>, so it works by keyboard and with scripts off; the area of the page you are on is open.
    --}}
    <div x-data="adminNav" @keydown.escape.window="close" class="admin-ui min-h-screen bg-[var(--admin-canvas)] lg:flex">
        <div x-cloak x-show="open" @click="close" class="fixed inset-0 z-40 bg-night/60 lg:hidden"></div>

        <nav id="admin-menu" aria-label="{{ __('layout.admin_navigation') }}" x-bind:class="drawerClass"
             class="motif-dark w-full bg-night text-white lg:sticky lg:top-0 lg:flex lg:h-screen lg:w-64 lg:shrink-0 lg:flex-col">
            <div class="flex h-16 shrink-0 items-center justify-between gap-3 border-b border-white/10 px-5">
                <a href="{{ Route::has('admin.dashboard') ? route('admin.dashboard') : url('/admin') }}" class="flex min-w-0 items-center gap-3 rounded-lg focus-visible:outline-accent-400">
                    <span aria-hidden="true" class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-brand-600 text-base font-bold text-white shadow-sm">{{ mb_strtoupper(mb_substr($brand, 0, 1)) }}</span>
                    <span class="min-w-0">
                        <span class="block truncate font-semibold leading-tight">{{ $brand }}</span>
                        <span class="block text-xs leading-tight text-white/75">{{ __('layout.admin_ui.panel') }}</span>
                    </span>
                </a>
                <button type="button" x-cloak x-ref="closeButton" @click="close" class="flex size-10 items-center justify-center rounded-lg text-white/80 hover:bg-white/10 hover:text-white focus-visible:outline-accent-400 lg:hidden" aria-label="{{ __('layout.admin_ui.close_menu') }}">
                    <svg aria-hidden="true" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>

            <div class="flex-1 space-y-1 overflow-y-auto px-3 py-4">
                @foreach ($menu['top'] as $item)
                    <a href="{{ route($item['route'], $item['params']) }}" @if ($isActive($item)) aria-current="page" @endif
                       class="{{ $link }} min-h-10 font-medium {{ $isActive($item) ? 'bg-white/10 text-white shadow-[inset_3px_0_0_var(--color-accent-400)]' : 'text-white/85 hover:bg-white/5 hover:text-white' }}">
                        @include('partials.admin.nav-icon', ['key' => 'dashboard'])<span>{{ __($item['label']) }}</span>
                    </a>
                @endforeach

                @foreach ($menu['areas'] as $area)
                    @php($current = collect($area['items'])->contains($isActive))
                    <details class="group" data-nav-area="{{ $area['key'] }}" @if ($current) open @endif>
                        <summary class="{{ $link }} min-h-10 cursor-pointer list-none font-medium [&::-webkit-details-marker]:hidden {{ $current ? 'text-white' : 'text-white/85 hover:bg-white/5 hover:text-white' }}">
                            @include('partials.admin.nav-icon', ['key' => $area['key']])
                            <span class="flex-1">{{ __('layout.nav.'.$area['key']) }}</span>
                            <svg class="size-4 shrink-0 text-white/60 transition-transform group-open:rotate-90 rtl:-scale-x-100" aria-hidden="true" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 4 6 6-6 6"/></svg>
                        </summary>
                        <ul class="mb-2 ms-[1.4rem] mt-0.5 space-y-0.5 border-s border-white/10 ps-3">
                            @foreach ($area['items'] as $item)
                                <li><a href="{{ route($item['route'], $item['params']) }}" @if ($isActive($item)) aria-current="page" @endif
                                       class="{{ $link }} min-h-9 py-1.5 {{ $isActive($item) ? 'bg-white/10 font-semibold text-white shadow-[inset_3px_0_0_var(--color-accent-400)]' : 'text-white/75 hover:bg-white/5 hover:text-white' }}">{{ __($item['label']) }}</a></li>
                            @endforeach
                        </ul>
                    </details>
                @endforeach
            </div>
        </nav>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="sticky top-0 z-30 border-b border-line bg-surface/95 backdrop-blur">
                <div class="flex h-16 items-center gap-2 px-4 sm:gap-3 lg:px-8">
                    <button type="button" x-cloak @click="toggle" x-bind:aria-expanded="open.toString()" aria-controls="admin-menu"
                            class="flex size-10 shrink-0 items-center justify-center rounded-lg border border-line text-ink hover:bg-surface-alt lg:hidden">
                        <svg aria-hidden="true" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                        <span class="sr-only">{{ __('layout.menu') }}</span>
                    </button>

                    @if ($user && Route::has('admin.search'))
                        <form method="GET" action="{{ route('admin.search') }}" role="search" class="min-w-0 max-w-xl flex-1">
                            <label for="admin-search" class="sr-only">{{ __('platform::productivity.search.label') }}</label>
                            <div class="relative">
                                <svg aria-hidden="true" class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                                <input id="admin-search" name="q" type="search" maxlength="60" value="{{ request()->routeIs('admin.search') ? request('q') : '' }}" placeholder="{{ __('platform::productivity.search.go') }}"
                                       class="block h-10 w-full rounded-lg border border-line bg-surface-alt ps-9 pe-3 text-sm placeholder:text-muted focus:border-brand-600 focus:bg-surface focus:outline-3 focus:outline-brand-500/30">
                            </div>
                        </form>
                    @endif

                    <div class="ms-auto flex shrink-0 items-center gap-1 sm:gap-2">
                        <a href="{{ url('/'.app()->getLocale()) }}" class="hidden min-h-10 items-center gap-1.5 rounded-lg px-3 text-sm font-medium text-muted hover:bg-surface-alt hover:text-ink md:inline-flex">
                            <svg aria-hidden="true" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 10.5 12 4l8 6.5V20H4z"/><path d="M9.5 20v-5h5v5"/></svg>{{ __('layout.admin_ui.view_shop') }}
                        </a>
                        <x-platform::language-switcher />
                        @if ($user)
                            <details class="relative" data-popover>
                                <summary class="flex min-h-10 cursor-pointer list-none items-center gap-2 rounded-full py-1 pe-2 ps-1 hover:bg-surface-alt [&::-webkit-details-marker]:hidden">
                                    <span aria-hidden="true" class="flex size-8 items-center justify-center rounded-full bg-brand-600 text-xs font-bold text-white">{{ $initials }}</span>
                                    <span class="hidden max-w-40 truncate text-sm font-medium md:block">{{ $user->name }}</span>
                                    <span class="sr-only md:hidden">{{ __('layout.admin_ui.account') }}</span>
                                    <svg aria-hidden="true" class="size-4 text-muted" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m5 8 5 5 5-5"/></svg>
                                </summary>
                                <div class="absolute end-0 z-40 mt-2 w-64 rounded-xl border border-line bg-surface p-2 shadow-lift">
                                    <div class="border-b border-line px-3 pb-3 pt-2">
                                        <p class="truncate text-sm font-semibold">{{ $user->name }}</p>
                                        @if ($user->email)<p class="truncate text-xs text-muted">{{ $user->email }}</p>@endif
                                    </div>
                                    <ul class="py-1 text-sm">
                                        @if (Route::has('admin.sessions'))<li><a href="{{ route('admin.sessions') }}" class="flex min-h-10 items-center rounded-lg px-3 hover:bg-surface-alt">{{ __('identity-access::admin.menu.sessions') }}</a></li>@endif
                                        <li><a href="{{ url('/'.app()->getLocale()) }}" class="flex min-h-10 items-center rounded-lg px-3 hover:bg-surface-alt md:hidden">{{ __('layout.admin_ui.view_shop') }}</a></li>
                                    </ul>
                                    <form method="POST" action="{{ route('admin.logout') }}" class="border-t border-line pt-1">
                                        @csrf
                                        <button class="flex min-h-10 w-full items-center gap-2 rounded-lg px-3 text-start text-sm font-medium text-ink hover:bg-surface-alt">
                                            <svg aria-hidden="true" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 16l-4-4 4-4M6 12h10"/></svg>{{ __('layout.sign_out') }}
                                        </button>
                                    </form>
                                </div>
                            </details>
                        @endif
                    </div>
                </div>
            </header>

            <main id="main" class="min-w-0 flex-1 px-4 py-6 sm:px-6 lg:px-8 lg:py-8" tabindex="-1">
                <div class="mx-auto w-full max-w-[96rem]">
                    @yield('content')
                </div>
            </main>
        </div>
    </div>
@endsection
