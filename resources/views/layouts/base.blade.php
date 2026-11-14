<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('app.name'))</title>
    <x-platform::seo-head :title="trim($__env->yieldContent('title'))" />
    <style>{!! app(\App\Modules\Platform\Support\Branding::class)->cssVariables() !!}</style>
    {{-- Brand icons (files in public/, sources in docs/Logos/favicon). The phone's browser bar takes the shop's brand colour. --}}
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="48x48">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="manifest" href="{{ asset('site.webmanifest') }}">
    <meta name="theme-color" content="{{ app(\App\Modules\Platform\Support\Branding::class)->color() }}">
    @stack('head')
    @php
        // Preload only the fonts this language needs for the first paint (Latin always; Bangla regular on /bn): fewer late swaps, less layout shift.
        $fonts = ['node_modules/@fontsource-variable/inter/files/inter-latin-wght-normal.woff2'];
        if (app()->getLocale() === 'bn') {
            $fonts[] = 'node_modules/@fontsource/noto-sans-bengali/files/noto-sans-bengali-bengali-400-normal.woff2';
        }
    @endphp
    @foreach ($fonts as $font)
        @php
            try { $fontUrl = \Illuminate\Support\Facades\Vite::asset($font); } catch (\Throwable) { $fontUrl = null; }
        @endphp
        @if ($fontUrl)<link rel="preload" href="{{ $fontUrl }}" as="font" type="font/woff2" crossorigin>@endif
    @endforeach
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-surface text-ink antialiased">
    <a href="#main" class="skip-link">{{ __('layout.skip_to_content') }}</a>
    @yield('body')
</body>
</html>
