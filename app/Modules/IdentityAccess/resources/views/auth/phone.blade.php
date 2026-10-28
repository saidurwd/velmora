@extends('layouts.storefront')

@section('title', __('identity-access::auth.phone.title'))

@section('content')
<div class="mx-auto max-w-md space-y-6">
    <h1 class="text-2xl font-bold">{{ __('identity-access::auth.phone.heading') }}</h1>
    <p class="text-muted">{{ __('identity-access::auth.phone.intro') }}</p>
    <x-platform::form.errors />

    <form method="POST" action="{{ route('login.phone') }}" class="space-y-4" novalidate>
        @csrf
        <x-platform::form.field name="phone" type="tel" inputmode="tel" :label="__('identity-access::auth.phone.label')" :help="__('identity-access::auth.phone.help')" autocomplete="tel" required />
        <x-platform::form.button class="w-full">{{ __('identity-access::auth.phone.submit') }}</x-platform::form.button>
    </form>

    <p class="text-sm"><a class="underline" href="{{ route('login') }}">{{ __('identity-access::auth.phone.use_password') }}</a></p>
</div>
@endsection
