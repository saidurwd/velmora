@extends('layouts.storefront')

@section('title', __('identity-access::auth.login.title'))

@section('content')
<div class="mx-auto max-w-md space-y-6">
    <h1 class="text-2xl font-bold">{{ __('identity-access::auth.login.heading') }}</h1>

    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    <x-platform::form.errors />

    <form method="POST" action="{{ route('login') }}" class="space-y-4" novalidate>
        @csrf
        <x-platform::form.field name="identifier" :label="__('identity-access::auth.login.identifier')" autocomplete="username" required />
        <x-platform::form.field name="password" type="password" :label="__('identity-access::auth.login.password')" autocomplete="current-password" required />
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="remember" value="1" @checked(old('remember')) class="size-4">
            {{ __('identity-access::auth.login.remember') }}
        </label>
        <x-platform::form.button class="w-full">{{ __('identity-access::auth.login.submit') }}</x-platform::form.button>
    </form>

    <div class="space-y-2 text-sm">
        <p><a class="underline" href="{{ route('login.phone') }}">{{ __('identity-access::auth.login.use_phone') }}</a></p>
        <p><a class="underline" href="{{ route('password.request') }}">{{ __('identity-access::auth.login.forgot') }}</a></p>
        <p>{{ __('identity-access::auth.login.no_account') }} <a class="underline" href="{{ route('register') }}">{{ __('identity-access::auth.login.register') }}</a></p>
    </div>
</div>
@endsection
