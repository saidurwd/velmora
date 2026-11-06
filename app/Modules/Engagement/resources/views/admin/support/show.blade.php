@extends('layouts.admin')

@section('title', $ticket->reference.' · '.$ticket->subject)

@section('content')
<div class="max-w-4xl space-y-6">
    <p><a class="text-sm underline" href="{{ route('admin.support.index') }}">← {{ __('engagement::support.desk_title') }}</a></p>
    <div class="space-y-1">
        <h1 class="text-2xl font-bold">{{ $ticket->subject }}</h1>
        <p class="flex flex-wrap items-center gap-2 text-sm text-muted">{{ $ticket->reference }} · {{ __('engagement::support.category.'.$ticket->category) }} · {{ strtoupper($ticket->locale) }}
            <x-platform::badge :tone="$ticket->status === 'answered' ? 'success' : 'neutral'">{{ __('engagement::support.status_staff.'.$ticket->status) }}</x-platform::badge></p>
        <p class="text-sm">{{ __('engagement::support.customer') }}: {{ $customer?->name ?? __('engagement::support.erased') }}@if ($order) · {{ __('engagement::support.about_order') }} <a class="underline" href="{{ route('admin.orders.show', $order['public_id']) }}">{{ $order['number'] }}</a>@endif · {{ __('engagement::support.assignee_label') }}: {{ $assignee ?? '—' }}</p>
    </div>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    @error('support')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror

    @include('engagement::partials.support-thread', ['messages' => $messages, 'fileRoute' => 'admin.support.file', 'staff' => true])

    <div class="flex flex-wrap items-end gap-4 border-t border-line pt-4">
        @if ($canReply)
        <form method="POST" action="{{ route('admin.support.assign', $ticket->public_id) }}" class="flex flex-wrap items-end gap-2">@csrf
            <div><label for="assignee" class="block text-sm font-medium">{{ __('engagement::support.assign_to') }}</label>
                <select id="assignee" name="assignee" class="mt-1 rounded-md border border-line px-3 py-2">
                    <option value="">{{ __('engagement::support.unassigned') }}</option>
                    @foreach ($assignable as $id => $name)@if ($canManage || $id === $myId)<option value="{{ $id }}" @selected((int) $ticket->assignee_id === $id)>{{ $name }}</option>@endif @endforeach
                </select></div>
            <x-platform::btn type="submit" size="sm" variant="secondary">{{ __('engagement::support.save') }}</x-platform::btn>
        </form>
        @endif
        @if ($canManage)
            @if ($ticket->status === 'closed')
                <form method="POST" action="{{ route('admin.support.reopen', $ticket->public_id) }}">@csrf<x-platform::btn type="submit" size="sm" variant="secondary">{{ __('engagement::support.reopen_button') }}</x-platform::btn></form>
            @else
                <form method="POST" action="{{ route('admin.support.close', $ticket->public_id) }}">@csrf<x-platform::btn type="submit" size="sm" variant="secondary">{{ __('engagement::support.close_staff_button') }}</x-platform::btn></form>
            @endif
        @endif
    </div>

    @if ($canReply && $ticket->status !== 'closed')
    <form method="POST" action="{{ route('admin.support.reply', $ticket->public_id) }}" enctype="multipart/form-data" class="space-y-4 rounded-lg border border-line p-4" novalidate>
        @csrf
        @if ($canned !== [])
        <div class="flex flex-wrap items-end gap-2" role="group" aria-label="{{ __('engagement::support.canned_label') }}">
            <span class="text-sm font-medium">{{ __('engagement::support.canned_label') }}:</span>
            @foreach ($canned as $c)<a class="rounded-md border border-line px-2 py-1 text-sm underline" href="{{ route('admin.support.show', ['ticket' => $ticket->public_id, 'canned' => $c['id']]) }}">{{ $c['title'] }}</a>@endforeach
        </div>
        @endif
        <div>
            <label for="body" class="block text-sm font-medium">{{ __('engagement::support.answer_label') }}</label>
            <textarea id="body" name="body" rows="6" minlength="5" maxlength="5000" required class="mt-1 block w-full rounded-md border border-line px-3 py-2">{{ old('body', $prefill) }}</textarea>
        </div>
        <div><label for="files" class="block text-sm font-medium">{{ __('engagement::support.files_label') }}</label><input id="files" name="files[]" type="file" multiple accept="image/jpeg,image/png,image/webp,application/pdf" class="mt-1 block w-full text-sm"></div>
        <label class="flex items-start gap-2"><input type="checkbox" name="internal" value="1" class="mt-1 size-4"><span>{{ __('engagement::support.internal_note_label') }}</span></label>
        <x-platform::btn type="submit">{{ __('engagement::support.answer_button') }}</x-platform::btn>
    </form>
    @endif
</div>
@endsection
