@extends('layouts.admin')

@section('title', __('platform::admin.dashboard'))

@section('content')
    <h1 class="text-2xl font-bold">{{ __('platform::admin.dashboard') }}</h1>
    @if (session('status'))<x-platform::alert type="info" class="mt-4">{{ session('status') }}</x-platform::alert>@endif
    <p class="mt-2 text-muted">{{ __('platform::admin.welcome') }}</p>
@endsection
