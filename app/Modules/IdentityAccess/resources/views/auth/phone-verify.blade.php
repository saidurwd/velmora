@extends('layouts.storefront')

@section('title', __('identity-access::auth.phone.code_title'))

@section('content')
<div class="mx-auto max-w-md space-y-6">
    <h1 class="text-2xl font-bold">{{ __('identity-access::auth.phone.code_title') }}</h1>
    <p class="text-muted">{{ __('identity-access::auth.phone.code_intro', ['phone' => $phoneMasked]) }}</p>
    <x-platform::form.errors />

    <form method="POST" action="{{ route('login.phone.verify') }}" class="space-y-4" novalidate>
        @csrf
        <x-platform::form.field name="code" inputmode="numeric" autocomplete="one-time-code" :label="__('identity-access::auth.phone.code_label')" required />
        <x-platform::form.button class="w-full">{{ __('identity-access::auth.phone.code_submit') }}</x-platform::form.button>
    </form>

    <p class="text-sm"><a class="underline" href="{{ route('login.phone') }}">{{ __('identity-access::auth.phone.change_number') }}</a></p>
</div>
@endsection
