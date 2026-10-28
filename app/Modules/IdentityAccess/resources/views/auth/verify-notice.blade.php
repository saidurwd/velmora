@extends('layouts.storefront')

@section('title', __('identity-access::auth.verify.notice_title'))

@section('content')
<div class="mx-auto max-w-md space-y-4">
    <h1 class="text-2xl font-bold">{{ __('identity-access::auth.verify.notice_title') }}</h1>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    <p>{{ __('identity-access::auth.verify.notice_message') }}</p>
    <form method="POST" action="{{ route('verification.send') }}">
        @csrf
        <x-platform::form.button variant="secondary">{{ __('identity-access::auth.verify.resend') }}</x-platform::form.button>
    </form>
</div>
@endsection
