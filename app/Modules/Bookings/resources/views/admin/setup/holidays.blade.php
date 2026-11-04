@extends('layouts.admin')

@section('title', __('bookings::setup.tab.holidays'))

@section('content')
<div class="max-w-3xl space-y-6">
    <h1 class="text-2xl font-bold">{{ __('bookings::setup.title') }}</h1>
    @include('bookings::admin.setup._nav')
    <p class="text-sm text-muted">{{ __('bookings::setup.holidays_help') }}</p>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    @error('setup')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror
    @if ($rows->isEmpty())
        <p class="text-muted">{{ __('bookings::setup.no_holidays') }}</p>
    @else
    <ul class="space-y-2">
        @foreach ($rows as $h)
        <li class="flex flex-wrap items-center gap-3"><span>{{ $h->on_date }} — {{ $h->name }}</span>
            @if (auth('admin')->user()?->can('bookings.manage'))<form method="POST" action="{{ route('admin.bookings.setup.holidays.remove', ['date' => $h->on_date]) }}">@csrf @method('DELETE')<x-platform::btn type="submit" size="sm" variant="secondary">{{ __('bookings::setup.remove') }}<span class="sr-only"> {{ $h->on_date }}</span></x-platform::btn></form>@endif</li>
        @endforeach
    </ul>
    @endif
    @if (auth('admin')->user()?->can('bookings.manage'))
    <form method="POST" action="{{ route('admin.bookings.setup.holidays.add') }}" class="flex flex-wrap items-end gap-3">
        @csrf
        <div><label for="h-date" class="block text-sm font-medium">{{ __('bookings::admin.date') }}</label><input id="h-date" type="date" name="date" required class="mt-1 rounded-md border border-line px-3 py-2"></div>
        <div><label for="h-name" class="block text-sm font-medium">{{ __('bookings::setup.name') }}</label><input id="h-name" name="name" maxlength="120" required class="mt-1 rounded-md border border-line px-3 py-2"></div>
        <x-platform::btn type="submit" size="sm">{{ __('bookings::setup.add') }}</x-platform::btn>
    </form>
    @endif
</div>
@endsection
