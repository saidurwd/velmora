@extends('layouts.admin-guest')

@section('title', __('identity-access::admin.login.title'))

@section('content')
<div class="space-y-6">
    <h1 class="text-2xl font-bold">{{ __('identity-access::admin.login.heading') }}</h1>
    <x-platform::form.errors />

    <form method="POST" action="{{ route('admin.login') }}" class="space-y-4" novalidate>
        @csrf
        <x-platform::form.field name="email" type="email" :label="__('identity-access::admin.login.email')" autocomplete="username" required />
        <x-platform::form.field name="password" type="password" :label="__('identity-access::admin.login.password')" autocomplete="current-password" required />
        <x-platform::form.button class="w-full">{{ __('identity-access::admin.login.submit') }}</x-platform::form.button>
    </form>
    <p class="text-sm"><a class="underline" href="{{ route('admin.password.request') }}">{{ __('identity-access::auth.login.forgot') }}</a></p>
</div>
@endsection
