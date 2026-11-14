@extends('layouts.base')

@php
    use App\Modules\Cms\Contracts\MenuReader;
    use App\Modules\Cms\Contracts\PageDirectory;
    use App\Modules\Payments\Contracts\PaymentMethodCatalog;
    use App\Modules\Platform\Support\Branding;
    use App\Modules\Platform\Support\BusinessProfile;
    use App\Modules\Platform\Support\StorefrontCache;

    $brand = app(Branding::class);
    $business = app(BusinessProfile::class);
    $sf = app(StorefrontCache::class);
    $locale = app()->getLocale();
    $home = url('/'.$locale);
    $customer = auth('web')->user();
    $headerMenu = $sf->remember('menu-header', fn () => app(MenuReader::class)->items('header', $locale));
    $footerMenu = $sf->remember('menu-footer', fn () => app(MenuReader::class)->items('footer', $locale));
    $pageLinks = $footerMenu !== [] ? [] : $sf->remember('footer-links', fn () => app(PageDirectory::class)->published($locale));
    // the ways to pay the shop really offers right now (plain labels, so the footer never promises a method that is switched off)
    $payWays = $sf->remember('pay-ways', fn () => array_values(array_map(fn ($o) => $o->label, app(PaymentMethodCatalog::class)->options(100000, ['physical'], $locale))));
@endphp

@section('body')
    {{-- Top bar: the promise, help, orders and the language switch. --}}
    <div class="motif-dark bg-brand-700 text-sm text-white">
        <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-1.5">
            <p class="flex min-w-0 items-center gap-2">
                <svg aria-hidden="true" class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h11v10H3zM14 9h4l3 3.5V16h-7z"/><circle cx="7" cy="18" r="1.8"/><circle cx="17.5" cy="18" r="1.8"/></svg>
                <span class="truncate">{{ __('layout.store.topbar_promise') }}</span>
            </p>
            <div class="flex shrink-0 items-center gap-4">
                <a href="{{ url('/'.$locale.'/help') }}" class="hidden underline-offset-2 hover:underline sm:inline">{{ __('layout.store.help') }}</a>
                <a href="{{ url('/'.$locale.'/account/orders') }}" class="hidden underline-offset-2 hover:underline md:inline">{{ __('layout.store.my_orders') }}</a>
                <x-platform::language-switcher tone="dark" />
            </div>
        </div>
    </div>

    <header class="sticky top-0 z-40 border-b border-line bg-surface">
        <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3 lg:flex-nowrap lg:gap-x-8">
            @if ($headerMenu !== [])
                {{-- Phones: the whole menu behind one button. A native <details>, so it works without JavaScript and is closed until asked for. --}}
                <details class="lg:hidden" data-popover>
                    <summary class="flex size-11 cursor-pointer list-none items-center justify-center rounded-full border border-line hover:border-ink [&::-webkit-details-marker]:hidden" aria-label="{{ __('layout.menu') }}">
                        <svg aria-hidden="true" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                    </summary>
                    <div class="absolute inset-x-0 top-full max-h-[75vh] overflow-y-auto border-b border-line bg-surface shadow-lift">@include('cms::menu.mobile', ['links' => $headerMenu])</div>
                </details>
            @endif

            <a href="{{ $home }}" class="flex shrink-0 items-center gap-3" aria-label="{{ $brand->name() }}">
                @if ($brand->logoUrl())
                    <img src="{{ $brand->logoUrl() }}" alt="" height="40" class="h-10 w-auto">
                @else
                    <span class="text-2xl font-bold tracking-tight text-brand-700">{{ $brand->name() }}</span>
                @endif
                @if ($brand->tagline() !== '')<span class="hidden text-sm text-muted xl:inline">{{ $brand->tagline() }}</span>@endif
            </a>

            <x-platform::search-box class="order-last basis-full lg:order-none lg:basis-auto lg:flex-1" :action="url('/'.$locale.'/search')" :suggest-url="url('/'.$locale.'/search/suggest')" />

            <div class="ms-auto flex shrink-0 items-center gap-1">
                <a href="{{ url('/'.$locale.'/'.($customer ? 'account' : 'login')) }}" class="inline-flex min-h-11 min-w-11 items-center justify-center gap-2 rounded-full px-3 hover:bg-surface-alt" aria-label="{{ $customer ? __('layout.store.account') : __('layout.store.sign_in') }}">
                    <svg aria-hidden="true" class="size-[22px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c1.5-3.5 4.5-5 8-5s6.5 1.5 8 5"/></svg>
                    <span aria-hidden="true" class="hidden text-sm font-medium sm:inline">{{ $customer ? __('layout.store.account') : __('layout.store.sign_in') }}</span>
                </a>
                <x-cart::link />
            </div>
        </div>
        @if ($headerMenu !== [])@include('cms::menu.header', ['links' => $headerMenu])@endif
    </header>

    <main id="main" class="mx-auto max-w-7xl px-4 py-8" tabindex="-1">
        @yield('content')
    </main>

    <footer class="mt-16 bg-night text-stone-300">
        <div class="motif-dark">
            <div class="mx-auto grid max-w-7xl gap-10 px-4 py-12 lg:grid-cols-[1fr_2fr]">
                <div class="space-y-4">
                    <p class="text-2xl font-bold tracking-tight text-white">{{ $brand->name() }}</p>
                    @if ($brand->tagline() !== '')<p class="max-w-sm">{{ $brand->tagline() }}</p>@endif
                    @if ($business->phone() !== '' || $business->email() !== '')
                        <ul class="space-y-1 text-sm">
                            @if ($business->phone() !== '')<li><a class="underline-offset-2 hover:text-white hover:underline" href="tel:{{ preg_replace('/[^0-9+]/', '', $business->phone()) }}">{{ $business->phone() }}</a></li>@endif
                            @if ($business->email() !== '')<li><a class="underline-offset-2 hover:text-white hover:underline" href="mailto:{{ $business->email() }}">{{ $business->email() }}</a></li>@endif
                        </ul>
                    @endif
                    {{-- not during checkout: there the page shows the ways THIS order can be paid, which may differ (a digital order cannot be paid in cash) --}}
                    @if ($payWays !== [] && ! $__env->hasSection('checkout'))
                        <div>
                            <p class="text-sm font-semibold text-white">{{ __('layout.store.we_accept') }}</p>
                            <ul class="mt-2 flex flex-wrap gap-2 text-xs">
                                @foreach ($payWays as $way)<li class="rounded-full border border-white/25 px-3 py-1">{{ $way }}</li>@endforeach
                            </ul>
                        </div>
                    @endif
                </div>
                <div>
                    @if ($footerMenu !== [])@include('cms::menu.footer', ['links' => $footerMenu])@endif
                    @if ($pageLinks !== [])
                        <nav aria-label="{{ __('platform::ui.info_links') }}">
                            <ul class="flex flex-wrap gap-x-6 gap-y-2 text-sm">
                                @foreach ($pageLinks as $link)<li><a class="underline-offset-2 hover:text-white hover:underline" href="{{ url('/'.$locale.'/pages/'.rawurlencode($link->slug)) }}">{{ $link->title }}</a></li>@endforeach
                            </ul>
                        </nav>
                    @endif
                </div>
            </div>
        </div>
        <div class="border-t border-white/10">
            <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3 px-4 py-5 text-sm">
                <span>&copy; {{ now()->year }} {{ $brand->name() }}. {{ __('platform::ui.footer_rights') }}</span>
                <x-platform::consent-banner />
            </div>
        </div>
    </footer>
@endsection
