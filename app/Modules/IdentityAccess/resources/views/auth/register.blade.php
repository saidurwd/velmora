@extends('layouts.storefront')

@section('title', __('identity-access::auth.register.title'))

@section('content')
<div class="mx-auto max-w-md space-y-6">
    <h1 class="text-2xl font-bold">{{ __('identity-access::auth.register.heading') }}</h1>
    <x-platform::form.errors />

    <form method="POST" action="{{ route('register') }}" class="space-y-4" novalidate>
        @csrf
        <x-platform::form.field name="name" :label="__('identity-access::auth.register.name')" autocomplete="name" required />
        <x-platform::form.field name="email" type="email" :label="__('identity-access::auth.register.email')" autocomplete="email" required />
        <x-platform::form.field name="password" type="password" :label="__('identity-access::auth.register.password')" :help="__('identity-access::auth.register.password_help')" autocomplete="new-password" required />
        <x-platform::form.field name="password_confirmation" type="password" :label="__('identity-access::auth.register.password_confirm')" autocomplete="new-password" required />
        <x-platform::form.button class="w-full">{{ __('identity-access::auth.register.submit') }}</x-platform::form.button>
    </form>

    <p class="text-sm"><a class="underline" href="{{ route('login.phone') }}">{{ __('identity-access::auth.login.use_phone') }}</a></p>
    <p class="text-sm">{{ __('identity-access::auth.register.have_account') }} <a class="underline" href="{{ route('login') }}">{{ __('identity-access::auth.register.login') }}</a></p>
</div>
@endsection
