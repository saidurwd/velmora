@extends('layouts.admin')

@section('title', $b['service'])

@php
    $fmt = fn ($d) => \App\Modules\Platform\Support\Fmt::dateTime($d);
    $money = fn ($m) => \App\Modules\Platform\Support\Fmt::money($m);
    $cents = fn (int $minor) => $money(\App\Modules\Platform\DTOs\Money::of($minor));
    $pct = fn (int $bp) => rtrim(rtrim(number_format($bp / 100, 2, '.', ''), '0'), '.');
    $c = $b['cancellation'];
    $t = $b['terms'];
@endphp

@section('content')
<div class="max-w-4xl space-y-6">
    <header class="space-y-1">
        <h1 class="text-2xl font-bold">{{ $b['service'] }} <span class="text-base font-normal text-muted">({{ $b['sku'] }})</span></h1>
        <p><x-platform::badge :tone="in_array($b['status'], ['confirmed', 'in_progress', 'completed'], true) ? 'success' : ($b['status'] === 'held' ? 'neutral' : 'danger')">{{ __('bookings::mine.status.'.$b['status']) }}</x-platform::badge></p>
        <p><a href="{{ route('admin.bookings.index') }}" class="inline-flex min-h-11 items-center underline">{{ __('bookings::admin.back') }}</a></p>
    </header>

    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    @error('booking')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror

    <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
        <div><dt class="text-muted">{{ __('bookings::admin.col.when') }}</dt><dd>{{ $fmt($b['starts_at']) }} – {{ $fmt($b['ends_at']) }}</dd></div>
        <div><dt class="text-muted">{{ __('bookings::admin.col.resource') }}</dt><dd>{{ $b['resource'] }}</dd></div>
        <div><dt class="text-muted">{{ __('bookings::admin.col.order') }}</dt><dd>@if ($b['order_public_id'])<a href="{{ route('admin.orders.show', ['order' => $b['order_public_id']]) }}" class="underline">{{ $b['order_number'] }}</a>@endif</dd></div>
        <div><dt class="text-muted">{{ __('bookings::admin.col.paid') }}</dt><dd>{{ $money($b['paid']) }}</dd></div>
        <div><dt class="text-muted">{{ __('bookings::admin.col.phone') }}</dt><dd>{{ $b['customer_phone'] }}@if ($b['customer_email']) · {{ $b['customer_email'] }}@endif</dd></div>
        <div><dt class="text-muted">{{ __('bookings::admin.moved') }}</dt><dd>{{ $b['moved'] }} / {{ $t['max_reschedules'] }}</dd></div>
        @if ($b['completed_at'])<div><dt class="text-muted">{{ __('bookings::admin.completed') }}</dt><dd>{{ $fmt($b['completed_at']) }} ({{ __('bookings::admin.by.'.$b['completed_by']) }})</dd></div>@endif
    </dl>

    <section aria-labelledby="terms-h" class="space-y-1 rounded-lg border border-line p-4 text-sm">
        <h2 id="terms-h" class="font-semibold">{{ __('bookings::admin.terms') }}</h2>
        <ul class="list-disc ps-5">
            @foreach ($t['windows'] as $w)<li>{{ __('bookings::admin.terms_window', ['hours' => $w['hours'], 'percent' => $pct($w['fee_bp'])]) }}</li>@endforeach
            <li>{{ __('bookings::admin.terms_late', ['percent' => $pct($t['late_fee_bp'])]) }}</li>
            <li>{{ __('bookings::admin.terms_no_show', ['percent' => $pct($t['no_show_fee_bp'])]) }}</li>
            <li>{{ __('bookings::admin.terms_move', ['hours' => $t['reschedule_hours'], 'max' => $t['max_reschedules']]) }}</li>
        </ul>
    </section>

    @if ($c)
        <section aria-labelledby="money-h" class="space-y-1 rounded-lg border border-line p-4">
            <h2 id="money-h" class="font-semibold">{{ __('bookings::admin.ended.'.$c->kind) }}</h2>
            <p>{{ __('bookings::admin.ended.line', ['by' => __('bookings::admin.by.'.$c->by_type), 'paid' => $cents((int) $c->paid_minor), 'fee' => $cents((int) $c->fee_minor), 'percent' => $pct((int) $c->fee_bp)]) }}@if ($c->fee_waived) ({{ __('bookings::admin.waived') }})@endif</p>
            <p><strong>{{ __('bookings::admin.ended.refund_due', ['amount' => $cents((int) $c->refund_due_minor)]) }}</strong></p>
            @if ((int) $c->refund_due_minor > 0 && $b['order_public_id'])<p class="text-sm text-muted">{{ __('bookings::admin.ended.approve_in_payments') }} <a href="{{ route('admin.orders.show', ['order' => $b['order_public_id']]) }}" class="underline">{{ __('bookings::admin.ended.open_order') }}</a></p>@endif
            @if ($c->reason)<p class="text-sm text-muted">{{ __('bookings::admin.reason') }}: {{ $c->reason }}</p>@endif
        </section>
    @endif

    @if ($can)
    @if ($b['can_complete'])
        <section aria-labelledby="done-h" class="space-y-2 rounded-lg border border-line p-4">
            <h2 id="done-h" class="font-semibold">{{ __('bookings::admin.act.complete') }}</h2>
            <form method="POST" action="{{ route('admin.bookings.complete', ['booking' => $b['public_id']]) }}">@csrf<x-platform::btn type="submit">{{ __('bookings::admin.act.complete') }}</x-platform::btn></form>
        </section>
    @endif

    @if ($b['can_no_show'])
        <section aria-labelledby="ns-h" class="space-y-3 rounded-lg border border-line p-4">
            <h2 id="ns-h" class="font-semibold">{{ __('bookings::admin.act.no_show') }}</h2>
            <p class="text-sm">{{ __('bookings::admin.preview', ['fee' => $cents($b['if_no_show']['fee_minor']), 'percent' => $pct($b['if_no_show']['fee_bp']), 'amount' => $cents($b['if_no_show']['refund_due_minor'])]) }}</p>
            <form method="POST" action="{{ route('admin.bookings.no-show', ['booking' => $b['public_id']]) }}" class="space-y-3">@csrf
                <div><label for="ns-reason" class="block text-sm font-medium">{{ __('bookings::admin.reason') }}</label><input id="ns-reason" name="reason" maxlength="255" class="mt-1 block w-full max-w-md rounded-md border border-line px-3 py-2"></div>
                <div class="flex items-center gap-2"><input id="ns-waive" type="checkbox" name="waive" value="1" class="h-5 w-5"><label for="ns-waive">{{ __('bookings::admin.waive') }}</label></div>
                <x-platform::btn type="submit" variant="danger">{{ __('bookings::admin.act.no_show') }}</x-platform::btn>
            </form>
        </section>
    @endif

    @if ($b['can_cancel'])
        <section aria-labelledby="cancel-h" class="space-y-3 rounded-lg border border-line p-4">
            <h2 id="cancel-h" class="font-semibold">{{ __('bookings::admin.act.cancel') }}</h2>
            <p class="text-sm">{{ __('bookings::admin.preview', ['fee' => $cents($b['if_cancelled']['fee_minor']), 'percent' => $pct($b['if_cancelled']['fee_bp']), 'amount' => $cents($b['if_cancelled']['refund_due_minor'])]) }}</p>
            <form method="POST" action="{{ route('admin.bookings.cancel', ['booking' => $b['public_id']]) }}" class="space-y-3">@csrf
                <div><label for="c-reason" class="block text-sm font-medium">{{ __('bookings::admin.reason') }}</label><input id="c-reason" name="reason" maxlength="255" required class="mt-1 block w-full max-w-md rounded-md border border-line px-3 py-2"></div>
                <div class="flex items-center gap-2"><input id="c-waive" type="checkbox" name="waive" value="1" class="h-5 w-5"><label for="c-waive">{{ __('bookings::admin.waive') }}</label></div>
                <x-platform::btn type="submit" variant="danger">{{ __('bookings::admin.act.cancel') }}</x-platform::btn>
            </form>
        </section>
    @endif

    @if ($b['can_move'] && $b['move'])
        <section aria-labelledby="move-h" class="space-y-3 rounded-lg border border-line p-4">
            <h2 id="move-h" class="font-semibold">{{ __('bookings::admin.act.move') }}</h2>
            <p class="text-sm text-muted">{{ __('bookings::admin.move_help') }}</p>
            <form method="GET" action="{{ route('admin.bookings.show', ['booking' => $b['public_id']]) }}" class="flex flex-wrap items-end gap-3">
                <div><label for="m-date" class="block text-sm font-medium">{{ __('bookings::admin.date') }}</label><input id="m-date" type="date" name="date" value="{{ $b['move']['date'] }}" class="mt-1 rounded-md border border-line px-3 py-2"></div>
                <x-platform::btn type="submit" variant="secondary" size="sm">{{ __('bookings::admin.show') }}</x-platform::btn>
            </form>
            @if ($b['move']['problem'])<p class="text-danger" role="alert">{{ __('bookings::errors.date_invalid') }}</p>@endif
            @if ($b['move']['slots'] === [])
                <p class="text-muted">{{ __('bookings::mine.no_times') }}</p>
            @else
            <form method="POST" action="{{ route('admin.bookings.move', ['booking' => $b['public_id']]) }}" class="space-y-3">@csrf
                <div><label for="m-start" class="block text-sm font-medium">{{ __('bookings::admin.new_time') }}</label>
                    <select id="m-start" name="start" class="mt-1 rounded-md border border-line px-3 py-2">
                        @foreach ($b['move']['slots'] as $slot)<option value="{{ $slot->startsAt->format('Y-m-d H:i:s') }}">{{ $slot->localStart()->locale(app()->getLocale())->isoFormat('LT') }}</option>@endforeach
                    </select></div>
                <div><label for="m-reason" class="block text-sm font-medium">{{ __('bookings::admin.reason') }}</label><input id="m-reason" name="reason" maxlength="255" required class="mt-1 block w-full max-w-md rounded-md border border-line px-3 py-2"></div>
                <x-platform::btn type="submit">{{ __('bookings::admin.act.move') }}</x-platform::btn>
            </form>
            @endif
        </section>
    @endif

    @if ($b['can_move'])
        <section aria-labelledby="re-h" class="space-y-3 rounded-lg border border-line p-4">
            <h2 id="re-h" class="font-semibold">{{ __('bookings::admin.act.reassign') }}</h2>
            @if ($b['reassign'] === [])
                <p class="text-muted">{{ __('bookings::admin.reassign_none') }}</p>
            @else
            <form method="POST" action="{{ route('admin.bookings.reassign', ['booking' => $b['public_id']]) }}" class="flex flex-wrap items-end gap-3">@csrf
                <div><label for="re-res" class="block text-sm font-medium">{{ __('bookings::admin.col.resource') }}</label>
                    <select id="re-res" name="resource" class="mt-1 rounded-md border border-line px-3 py-2">@foreach ($b['reassign'] as $r)<option value="{{ $r['public_id'] }}">{{ $r['name'] }}</option>@endforeach</select></div>
                <x-platform::btn type="submit">{{ __('bookings::admin.act.reassign') }}</x-platform::btn>
            </form>
            @endif
        </section>
    @endif
    @endif

    <section aria-labelledby="hist-h" class="space-y-2">
        <h2 id="hist-h" class="font-semibold">{{ __('bookings::admin.history') }}</h2>
        <ol class="space-y-1 text-sm">
            @foreach ($b['events'] as $e)<li>{{ \App\Modules\Platform\Support\Fmt::dateTime(\Carbon\CarbonImmutable::parse($e->created_at, 'UTC')) }} · <strong>{{ str_replace('_', ' ', $e->event) }}</strong> · {{ $e->actor_type }}@if ($e->note) — {{ $e->note }}@endif</li>@endforeach
        </ol>
    </section>

    @if ($b['notices']->isNotEmpty())
    <section aria-labelledby="msg-h" class="space-y-2">
        <h2 id="msg-h" class="font-semibold">{{ __('bookings::admin.messages') }}</h2>
        <ul class="space-y-1 text-sm">
            @foreach ($b['notices'] as $n)<li>{{ str_replace('_', ' ', $n->kind) }} · {{ $n->channel }} · {{ $n->status }} ({{ $n->attempts }})</li>@endforeach
        </ul>
    </section>
    @endif
</div>
@endsection
