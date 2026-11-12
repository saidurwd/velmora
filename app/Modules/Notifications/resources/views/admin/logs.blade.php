@extends('layouts.admin')

@section('title', __('notifications::admin.logs_title'))

@section('content')
<div class="max-w-6xl space-y-6">
    <h1 class="text-2xl font-bold">{{ __('notifications::admin.logs_title') }}</h1>
    <p class="text-sm text-muted">{{ __('notifications::admin.logs_help') }}</p>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    @error('log')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror
    @if ($health['dead'] > 0 || $health['failing'] > 0)
        <x-platform::alert type="error">{{ __('notifications::admin.health_bad', ['dead' => $health['dead'], 'failing' => $health['failing']]) }}</x-platform::alert>
    @else
        <p class="text-sm text-muted">{{ __('notifications::admin.health_ok', ['queued' => $health['queued']]) }}</p>
    @endif
    <form method="GET" action="{{ route('admin.notifications.logs') }}" class="grid items-end gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <div><label for="status" class="block text-sm font-medium">{{ __('notifications::admin.state') }}</label>
            <select id="status" name="status" class="mt-1 block w-full rounded-md border border-line px-3 py-2"><option value="">{{ __('notifications::admin.any') }}</option>@foreach ($statuses as $s)<option value="{{ $s }}" @selected(($filters['status'] ?? '') === $s)>{{ __('notifications::admin.status.'.$s) }}</option>@endforeach</select></div>
        <div><label for="template" class="block text-sm font-medium">{{ __('notifications::admin.message') }}</label>
            <select id="template" name="template" class="mt-1 block w-full rounded-md border border-line px-3 py-2"><option value="">{{ __('notifications::admin.any') }}</option>@foreach ($codes as $c)<option value="{{ $c }}" @selected(($filters['template'] ?? '') === $c)>{{ $c }}</option>@endforeach</select></div>
        <div><label for="channel" class="block text-sm font-medium">{{ __('notifications::admin.channel') }}</label>
            <select id="channel" name="channel" class="mt-1 block w-full rounded-md border border-line px-3 py-2"><option value="">{{ __('notifications::admin.any') }}</option>@foreach (['email', 'sms', 'push'] as $c)<option value="{{ $c }}" @selected(($filters['channel'] ?? '') === $c)>{{ __('notifications::admin.channels.'.$c) }}</option>@endforeach</select></div>
        <div><label for="from" class="block text-sm font-medium">{{ __('notifications::admin.from') }}</label><input id="from" type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="mt-1 block w-full rounded-md border border-line px-3 py-2"></div>
        <div><label for="to" class="block text-sm font-medium">{{ __('notifications::admin.to') }}</label><input id="to" type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="mt-1 block w-full rounded-md border border-line px-3 py-2"></div>
        <div><x-platform::btn type="submit" size="sm">{{ __('notifications::admin.filter') }}</x-platform::btn></div>
    </form>
    @if ($page->isEmpty())
        <p class="text-muted">{{ __('notifications::admin.none_logs') }}</p>
    @else
    <div class="overflow-x-auto"><table class="w-full text-left text-sm">
        <caption class="sr-only">{{ __('notifications::admin.logs_title') }}</caption>
        <thead><tr class="border-b border-line"><th scope="col" class="py-2 pr-3">{{ __('notifications::admin.when') }}</th><th scope="col" class="py-2 pr-3">{{ __('notifications::admin.message') }}</th><th scope="col" class="py-2 pr-3">{{ __('notifications::admin.to_whom') }}</th><th scope="col" class="py-2 pr-3">{{ __('notifications::admin.state') }}</th><th scope="col" class="py-2 pr-3">{{ __('notifications::admin.tries') }}</th><th scope="col" class="py-2 pr-3">{{ __('notifications::admin.why') }}</th></tr></thead>
        <tbody>
        @foreach ($page as $r)
            <tr class="border-b border-line align-top">
                <td class="py-2 pr-3">{{ \App\Modules\Platform\Support\Fmt::dateTime(\Carbon\CarbonImmutable::parse($r->created_at, 'UTC')) }}</td>
                <td class="py-2 pr-3">{{ $r->template_code }}<div class="text-xs text-muted">{{ __('notifications::admin.channels.'.$r->channel) }} · {{ strtoupper($r->locale) }}@if ($r->related) · {{ $r->related }}@endif</div></td>
                <td class="py-2 pr-3">{{ $r->recipient_masked }}</td>
                <td class="py-2 pr-3"><x-platform::badge :tone="in_array($r->status, ['sent'], true) ? 'success' : (in_array($r->status, ['dead', 'failed'], true) ? 'danger' : 'neutral')">{{ __('notifications::admin.status.'.$r->status) }}</x-platform::badge></td>
                <td class="py-2 pr-3">{{ $r->attempts }}</td>
                <td class="py-2 pr-3">{{ $r->skip_reason ? __('notifications::admin.skip.'.$r->skip_reason) : ($r->last_error ?? '') }}
                    @if ($can && $r->status === 'dead')
                        @if ($r->has_payload)
                        <form method="POST" action="{{ route('admin.notifications.logs.retry', ['log' => $r->public_id]) }}" class="mt-2 flex flex-wrap items-end gap-2">@csrf
                            <div><label for="rs-{{ $r->public_id }}" class="block text-xs font-medium">{{ __('notifications::admin.reason') }}</label><input id="rs-{{ $r->public_id }}" name="reason" maxlength="255" required class="mt-1 rounded-md border border-line px-2 py-2"></div>
                            <x-platform::btn type="submit" size="sm">{{ __('notifications::admin.retry') }}<span class="sr-only"> {{ $r->template_code }}</span></x-platform::btn>
                        </form>
                        @else<div class="text-xs text-muted">{{ __('notifications::admin.erased') }}</div>@endif
                    @endif</td>
            </tr>
        @endforeach
        </tbody></table></div>
    {{ $page->links() }}
    @endif
</div>
@endsection
