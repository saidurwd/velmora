@extends('layouts.storefront')

@section('title', config('app.name'))

@section('content')
    <h1 class="text-2xl font-bold">{{ __('platform::home.welcome', ['app' => config('app.name')]) }}</h1>
    <p class="mt-2 text-muted">{{ __('platform::home.coming_soon') }}</p>
@endsection
