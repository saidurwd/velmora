@extends('layouts.account')

@section('title', $ticket->subject)

@section('account')
<div class="max-w-2xl space-y-6">
    <p><a class="underline" href="{{ route('account.support') }}">{{ __('engagement::support.back') }}</a></p>
    <div class="space-y-1">
        <h1 class="text-2xl font-bold">{{ $ticket->subject }}</h1>
        <p class="flex flex-wrap items-center gap-2 text-sm text-muted">{{ __('engagement::support.reference') }} {{ $ticket->reference }} · {{ __('engagement::support.category.'.$ticket->category) }}@if ($order) · {{ __('engagement::support.about_order') }} <a class="underline" href="{{ route('account.orders.show', $order['public_id']) }}">{{ $order['number'] }}</a>@endif
            <x-platform::badge :tone="$ticket->status === 'answered' ? 'success' : 'neutral'">{{ __('engagement::support.status.'.$ticket->status) }}</x-platform::badge></p>
    </div>
    @error('support')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror

    @include('engagement::partials.support-thread', ['messages' => $messages, 'fileRoute' => 'account.support.file', 'staff' => false])

    @if ($ticket->status === 'closed')<p class="text-sm text-muted">{{ __('engagement::support.closed_note', ['days' => (int) config('engagement.support.reopen_days', 14)]) }}</p>@endif
    <form method="POST" action="{{ route('account.support.reply', $ticket->public_id) }}" enctype="multipart/form-data" class="space-y-4" novalidate>
        @csrf
        <div>
            <label for="body" class="block text-sm font-medium">{{ __('engagement::support.reply_label') }}</label>
            <textarea id="body" name="body" rows="5" minlength="5" maxlength="5000" required class="mt-1 block w-full rounded-md border border-line px-3 py-2">{{ old('body') }}</textarea>
            @error('body')<p class="mt-1 text-sm text-danger">{{ __('engagement::support.errors.too_short', ['min' => 5]) }}</p>@enderror
        </div>
        <div>
            <label for="files" class="block text-sm font-medium">{{ __('engagement::support.files_label') }}</label>
            <input id="files" name="files[]" type="file" multiple accept="image/jpeg,image/png,image/webp,application/pdf" class="mt-1 block w-full text-sm">
        </div>
        <x-platform::form.button>{{ __('engagement::support.reply_button') }}</x-platform::form.button>
    </form>
    @if ($ticket->status !== 'closed')
        <form method="POST" action="{{ route('account.support.close', $ticket->public_id) }}">@csrf
            <x-platform::btn type="submit" variant="secondary" size="sm">{{ __('engagement::support.close_button') }}</x-platform::btn>
        </form>
    @endif
</div>
@endsection
