@extends('layouts.storefront')

@section('title', __('errors.404.title'))

@section('content')
    <section class="mx-auto max-w-xl py-16 text-center">
        <p class="text-sm font-semibold text-muted">{{ __('errors.error_code', ['code' => 404]) }}</p>
        <h1 class="mt-2 text-3xl font-bold">{{ __('errors.404.title') }}</h1>
        <p class="mt-4 text-muted">{{ __('errors.404.message') }}</p>
        <a href="/" class="mt-8 inline-block rounded-md bg-brand-600 px-5 py-3 font-medium text-white hover:bg-brand-700">{{ __('errors.back_home') }}</a>
    </section>
@endsection
