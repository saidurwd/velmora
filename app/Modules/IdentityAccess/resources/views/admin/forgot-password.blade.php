@extends('layouts.admin-guest')

@section('title', __('identity-access::auth.forgot.title'))

@section('content')
<div class="space-y-6">
    <h1 class="text-2xl font-bold">{{ __('identity-access::auth.forgot.heading') }}</h1>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    <x-platform::form.errors />
    <form method="POST" action="{{ route('admin.password.email') }}" class="space-y-4" novalidate>
        @csrf
        <x-platform::form.field name="email" type="email" :label="__('identity-access::auth.forgot.email')" autocomplete="email" required />
        <x-platform::form.button class="w-full">{{ __('identity-access::auth.forgot.submit') }}</x-platform::form.button>
    </form>
    <p class="text-sm"><a class="underline" href="{{ route('admin.login') }}">{{ __('identity-access::auth.register.login') }}</a></p>
</div>
@endsection
