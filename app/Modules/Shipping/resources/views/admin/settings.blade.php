@extends('layouts.admin')

@section('title', __('shipping::fulfilment.settings.title'))

@section('content')
<div class="max-w-2xl space-y-6">
    <h1 class="text-2xl font-bold">{{ __('shipping::fulfilment.settings.title') }}</h1>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    @error('settings')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror
    <form method="POST" action="{{ route('admin.shipping.settings.update') }}" class="space-y-4">
        @csrf @method('PUT')
        <div><label for="window" class="block text-sm font-medium">{{ __('shipping::fulfilment.settings.window') }}</label><p class="text-sm text-muted">{{ __('shipping::fulfilment.settings.window_help') }}</p>
            <input id="window" name="window" type="number" min="1" max="365" value="{{ old('window', $values['window']) }}" @disabled(! $can) class="mt-1 block w-32 rounded-md border border-line px-3 py-2">@error('window')<p class="text-sm text-danger">{{ $message }}</p>@enderror</div>
        <div><label for="attempts" class="block text-sm font-medium">{{ __('shipping::fulfilment.settings.attempts') }}</label><p class="text-sm text-muted">{{ __('shipping::fulfilment.settings.attempts_help') }}</p>
            <input id="attempts" name="attempts" type="number" min="1" max="10" value="{{ old('attempts', $values['attempts']) }}" @disabled(! $can) class="mt-1 block w-32 rounded-md border border-line px-3 py-2">@error('attempts')<p class="text-sm text-danger">{{ $message }}</p>@enderror</div>
        @if ($can)<x-platform::btn type="submit" size="sm">{{ __('shipping::fulfilment.settings.save') }}</x-platform::btn>@else<p class="text-sm text-muted">{{ __('shipping::fulfilment.settings.only_view') }}</p>@endif
    </form>
</div>
@endsection
