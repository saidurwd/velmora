@extends($guest ? 'layouts.storefront' : 'layouts.account')

@section('title', $row['service'])

@php
    $fmtDate = fn ($d) => \App\Modules\Platform\Support\Fmt::dateTime($d);
    $money = fn ($m) => \App\Modules\Platform\Support\Fmt::money($m);
    $out = $row['if_cancelled'];
@endphp

@section($guest ? 'content' : 'account')
<div class="{{ $guest ? 'mx-auto max-w-3xl space-y-6' : 'space-y-6' }}">
    <header class="space-y-1">
        <h1 class="text-2xl font-bold">{{ $row['service'] }}</h1>
        <p><x-platform::badge :tone="in_array($row['status'], ['confirmed', 'in_progress', 'completed'], true) ? 'success' : ($row['status'] === 'held' ? 'neutral' : 'danger')">{{ __('bookings::mine.status.'.$row['status']) }}</x-platform::badge></p>
        <p>{{ $fmtDate($row['starts_at']) }} – {{ $fmtDate($row['ends_at']) }}</p>
        <p class="text-sm text-muted">{{ __('bookings::mine.with') }} {{ $row['resource'] }} · {{ __('bookings::mine.order') }} {{ $row['order_number'] }} · {{ $money($row['paid']) }}</p>
        @if ($urls['list'])<p><a href="{{ $urls['list'] }}" class="inline-flex min-h-11 items-center underline">{{ __('bookings::mine.back') }}</a></p>@endif
    </header>

    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    @error('booking')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror

    @if ($row['ended'])
        <section aria-labelledby="ended-h" class="space-y-1 rounded-lg border border-line p-4">
            <h2 id="ended-h" class="font-semibold">{{ __('bookings::mine.ended.'.$row['ended']['kind']) }}</h2>
            <p>{{ __('bookings::mine.ended.fee', ['fee' => $money($row['ended']['fee'])]) }}</p>
            <p>{{ __('bookings::mine.ended.refund', ['amount' => $money($row['ended']['refund_due'])]) }}</p>
            <p class="text-sm text-muted">{{ __('bookings::mine.refund_note') }}</p>
        </section>
    @endif

    @if ($row['can_complete'])
        <section aria-labelledby="done-h" class="space-y-2 rounded-lg border border-line p-4">
            <h2 id="done-h" class="font-semibold">{{ __('bookings::mine.done_title') }}</h2>
            <form method="POST" action="{{ $urls['complete'] }}">@csrf<x-platform::btn type="submit">{{ __('bookings::mine.done_button') }}</x-platform::btn></form>
        </section>
    @endif

    @if ($row['status'] === 'confirmed')
        <section aria-labelledby="move-h" class="space-y-3 rounded-lg border border-line p-4">
            <h2 id="move-h" class="font-semibold">{{ __('bookings::mine.move_title') }}</h2>
            @if ($row['can_move'])
                <p class="text-sm text-muted">{{ __('bookings::mine.move_help', ['left' => $row['moves_left'], 'hours' => $row['move_hours']]) }}</p>
                <form method="GET" action="{{ $urls['self'] }}" class="flex flex-wrap items-end gap-3">
                    <div><label for="date" class="block text-sm font-medium">{{ __('bookings::mine.day') }}</label>
                        <input id="date" type="date" name="date" value="{{ $date }}" min="{{ $min }}" max="{{ $max }}" class="mt-1 rounded-md border border-line px-3 py-2"></div>
                    <x-platform::btn type="submit" variant="secondary">{{ __('bookings::mine.show_times') }}</x-platform::btn>
                </form>
                @if ($problem)<p class="text-danger" role="alert">{{ __('bookings::errors.date_invalid') }}</p>@endif
                @if ($slots === [])
                    <p class="text-muted">{{ __('bookings::mine.no_times') }}</p>
                @else
                    <ul class="flex flex-wrap gap-2">
                    @foreach ($slots as $slot)
                        <li><form method="POST" action="{{ $urls['move'] }}">@csrf<input type="hidden" name="start" value="{{ $slot->startsAt->format('Y-m-d H:i:s') }}">
                            <button type="submit" class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-md border border-line px-4 py-2 hover:bg-surface-alt">{{ $slot->localStart()->locale(app()->getLocale())->isoFormat('LT') }}</button></form></li>
                    @endforeach
                    </ul>
                @endif
            @else
                <p class="text-muted">{{ __('bookings::mine.move_closed', ['hours' => $row['move_hours']]) }}</p>
            @endif
        </section>
    @endif

    @if ($row['can_cancel'])
        <section aria-labelledby="cancel-h" class="space-y-3 rounded-lg border border-line p-4">
            <h2 id="cancel-h" class="font-semibold">{{ __('bookings::mine.cancel_title') }}</h2>
            @if ($out['fee_minor'] === 0)
                <p>{{ __('bookings::mine.cancel_free', ['amount' => $money(\App\Modules\Platform\DTOs\Money::of($out['refund_due_minor']))]) }}</p>
            @else
                <p>{{ __('bookings::mine.cancel_fee', ['percent' => rtrim(rtrim(number_format($out['fee_bp'] / 100, 2, '.', ''), '0'), '.'), 'fee' => $money(\App\Modules\Platform\DTOs\Money::of($out['fee_minor'])), 'amount' => $money(\App\Modules\Platform\DTOs\Money::of($out['refund_due_minor']))]) }}</p>
            @endif
            <p class="text-sm text-muted">{{ __('bookings::mine.refund_note') }}</p>
            <form method="POST" action="{{ $urls['cancel'] }}" class="space-y-3">@csrf
                <div><label for="reason" class="block text-sm font-medium">{{ __('bookings::mine.reason') }}</label>
                    <input id="reason" name="reason" maxlength="255" class="mt-1 block w-full max-w-md rounded-md border border-line px-3 py-2"></div>
                <x-platform::btn type="submit" variant="danger">{{ __('bookings::mine.cancel_button') }}</x-platform::btn>
            </form>
        </section>
    @endif

</div>
@endsection
