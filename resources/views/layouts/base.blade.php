<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('app.name'))</title>
    <x-platform::seo-head :title="trim($__env->yieldContent('title'))" />
    <style>{!! app(\App\Modules\Platform\Support\Branding::class)->cssVariables() !!}</style>
    @stack('head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-surface text-ink antialiased">
    <a href="#main" class="skip-link">{{ __('layout.skip_to_content') }}</a>
    @yield('body')
</body>
</html>
