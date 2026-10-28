@extends('layouts.admin-guest')

@section('title', __('identity-access::auth.reset.title'))

@section('content')
<div class="space-y-6">
    <h1 class="text-2xl font-bold">{{ __('identity-access::auth.reset.heading') }}</h1>
    <x-platform::form.errors />
    <form method="POST" action="{{ route('admin.password.update') }}" class="space-y-4" novalidate>
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <x-platform::form.field name="email" type="email" :value="$email" :label="__('identity-access::auth.reset.email')" autocomplete="email" required />
        <x-platform::form.field name="password" type="password" :label="__('identity-access::auth.reset.password')" :help="__('identity-access::auth.register.password_help')" autocomplete="new-password" required />
        <x-platform::form.field name="password_confirmation" type="password" :label="__('identity-access::auth.reset.confirm')" autocomplete="new-password" required />
        <x-platform::form.button class="w-full">{{ __('identity-access::auth.reset.submit') }}</x-platform::form.button>
    </form>
</div>
@endsection
