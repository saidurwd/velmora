@extends('layouts.storefront')

@section('title', __('customers::account.title'))

@section('content')
<div class="space-y-6">
    <h1 class="text-2xl font-bold">{{ __('customers::account.title') }}</h1>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    <p>{{ __('customers::account.welcome', ['name' => $displayName]) }}</p>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <x-platform::form.button variant="secondary">{{ __('identity-access::auth.logout') }}</x-platform::form.button>
    </form>
</div>
@endsection
