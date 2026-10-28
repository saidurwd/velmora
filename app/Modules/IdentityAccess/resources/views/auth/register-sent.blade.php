@extends('layouts.storefront')

@section('title', __('identity-access::auth.register.sent_title'))

@section('content')
<div class="mx-auto max-w-md space-y-4">
    <h1 class="text-2xl font-bold">{{ __('identity-access::auth.register.sent_title') }}</h1>
    <x-platform::alert type="info">{{ __('identity-access::auth.register.sent_message') }}</x-platform::alert>
    <p class="text-sm"><a class="underline" href="{{ route('login') }}">{{ __('identity-access::auth.register.login') }}</a></p>
</div>
@endsection
