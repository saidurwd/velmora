@extends('layouts.storefront')

@section('title', __('bookings::mine.title'))

@section('content')
<div class="mx-auto max-w-lg space-y-6">
    <h1 class="text-2xl font-bold">{{ __('bookings::mine.title') }}</h1>
    <p>{{ __('bookings::mine.guest_intro', ['number' => $number, 'end' => $phoneEnd]) }}</p>
    @if (session('code_status'))<x-platform::alert type="success">{{ session('code_status') }}</x-platform::alert>@endif
    @if (session('code_problem'))<x-platform::alert type="error">{{ session('code_problem') }}</x-platform::alert>@endif
    <form method="POST" action="{{ $sendUrl }}">@csrf<x-platform::btn type="submit" variant="secondary">{{ __('bookings::mine.send_code') }}</x-platform::btn></form>
    <form method="POST" action="{{ $verifyUrl }}" class="space-y-3">@csrf
        <div><label for="code" class="block text-sm font-medium">{{ __('bookings::mine.code_label') }}</label>
            <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="12" required class="mt-1 block w-48 rounded-md border border-line px-3 py-2">
            @error('code')<p class="mt-1 text-sm text-danger" role="alert">{{ $message }}</p>@enderror</div>
        <x-platform::btn type="submit">{{ __('bookings::mine.open_bookings') }}</x-platform::btn>
    </form>
</div>
@endsection
