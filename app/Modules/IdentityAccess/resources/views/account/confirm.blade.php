@extends('layouts.account')

@section('title', __('identity-access::account.confirm.title'))

@section('account')
<div class="max-w-md space-y-6">
    <h1 class="text-2xl font-bold">{{ __('identity-access::account.confirm.title') }}</h1>
    <p class="text-muted">{{ __('identity-access::account.confirm.intro') }}</p>

    @if ($hasPassword)
        <form method="POST" action="{{ route('account.confirm') }}" class="space-y-4" novalidate>
            @csrf
            <x-platform::form.field name="password" type="password" :label="__('identity-access::auth.login.password')" autocomplete="current-password" required />
            <x-platform::form.button class="w-full">{{ __('identity-access::account.confirm.submit') }}</x-platform::form.button>
        </form>
    @endif

    @if ($hasPhone)
        <section class="space-y-4 border-t border-line pt-6">
            <h2 class="font-semibold">{{ __('identity-access::account.confirm.by_code') }}</h2>
            <form method="POST" action="{{ route('account.confirm.send') }}">
                @csrf
                <x-platform::form.button variant="secondary">{{ __('identity-access::account.confirm.send_code') }}</x-platform::form.button>
            </form>
            <form method="POST" action="{{ route('account.confirm.code') }}" class="space-y-4" novalidate>
                @csrf
                <x-platform::form.field name="code" inputmode="numeric" autocomplete="one-time-code" :label="__('identity-access::auth.phone.code_label')" required />
                <x-platform::form.button class="w-full">{{ __('identity-access::account.confirm.submit') }}</x-platform::form.button>
            </form>
        </section>
    @endif
</div>
@endsection
