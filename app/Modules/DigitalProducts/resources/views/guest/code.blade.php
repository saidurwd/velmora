@extends('layouts.storefront')

@section('title', __('digital-products::library.title'))

@section('content')
<div class="mx-auto max-w-lg space-y-6">
    <h1 class="text-2xl font-bold">{{ __('digital-products::library.title') }}</h1>
    <p>{{ __('digital-products::library.guest_intro', ['number' => $number, 'end' => $phoneEnd]) }}</p>
    @if (session('code_status'))<x-platform::alert type="success">{{ session('code_status') }}</x-platform::alert>@endif
    @if (session('code_problem'))<x-platform::alert type="error">{{ session('code_problem') }}</x-platform::alert>@endif
    <form method="POST" action="{{ $sendUrl }}">@csrf<x-platform::btn type="submit" variant="secondary">{{ __('digital-products::library.send_code') }}</x-platform::btn></form>
    <form method="POST" action="{{ $verifyUrl }}" class="space-y-3">@csrf
        <div><label for="code" class="block text-sm font-medium">{{ __('digital-products::library.code_label') }}</label>
            <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="12" required class="mt-1 block w-48 rounded-md border border-line px-3 py-2">
            @error('code')<p class="mt-1 text-sm text-danger" role="alert">{{ $message }}</p>@enderror</div>
        <x-platform::btn type="submit">{{ __('digital-products::library.open') }}</x-platform::btn>
    </form>
</div>
@endsection
