@extends('layouts.admin-guest')

@section('title', __('identity-access::admin.mfa.challenge_title'))

@section('content')
<div class="space-y-6">
    <h1 class="text-2xl font-bold">{{ __('identity-access::admin.mfa.challenge_title') }}</h1>
    <p>{{ __('identity-access::admin.mfa.challenge_intro') }}</p>
    <x-platform::form.errors />

    <form method="POST" action="{{ route('admin.mfa.challenge') }}" class="space-y-4" novalidate>
        @csrf
        <x-platform::form.field name="code" autocomplete="one-time-code" :label="__('identity-access::admin.mfa.code_or_recovery')" required />
        <x-platform::form.button class="w-full">{{ __('identity-access::admin.mfa.challenge_submit') }}</x-platform::form.button>
    </form>
</div>
@endsection
