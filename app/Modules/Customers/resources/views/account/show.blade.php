@extends('layouts.account')

@section('title', __('customers::account.title'))

@section('account')
    <h1 class="text-2xl font-bold">{{ __('customers::account.title') }}</h1>
    <p>{{ __('customers::account.welcome', ['name' => $displayName]) }}</p>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <x-platform::form.button variant="secondary">{{ __('identity-access::auth.logout') }}</x-platform::form.button>
    </form>
@endsection
