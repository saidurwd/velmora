@extends('layouts.account')

@section('title', __('shipping::returns.form_title'))

@section('account')
@php $fmt = fn ($d) => \App\Modules\Platform\Support\Fmt::date($d); @endphp
<div class="max-w-xl space-y-6">
    <p><a class="underline" href="{{ route('account.orders.show', $order) }}">{{ __('shipping::returns.back_to_order') }}</a></p>
    <h1 class="text-2xl font-bold">{{ __('shipping::returns.form_title') }}</h1>
    <p class="text-sm text-muted">{{ __('shipping::returns.form_help') }}</p>
    @error('return')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror
    @error('lines')<x-platform::alert type="error">{{ __('shipping::returns.choose_something') }}</x-platform::alert>@enderror

    <form method="POST" action="{{ route('account.returns.store', $order) }}" class="space-y-5" novalidate>
        @csrf
        <fieldset class="space-y-3">
            <legend class="font-medium">{{ __('shipping::returns.what') }}</legend>
            @foreach ($lines as $line)
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-md border border-line p-3">
                    <div>
                        <label for="line-{{ $line['position'] }}" class="font-medium">{{ $line['name'] }}</label>
                        <p class="text-sm text-muted">
                            @if ($line['open'])
                                {{ __('shipping::returns.can_send', ['count' => \App\Modules\Platform\Support\Fmt::number($line['left'])]) }} · {{ __('shipping::returns.until', ['date' => $fmt($line['until'])]) }}
                            @elseif ($line['until'] === null)
                                {{ __('shipping::returns.not_delivered') }}
                            @elseif ($line['left'] < 1)
                                {{ __('shipping::returns.nothing_left') }}
                            @else
                                {{ __('shipping::returns.window_closed', ['date' => $fmt($line['until'])]) }}
                            @endif
                        </p>
                    </div>
                    @if ($line['open'])
                        <input id="line-{{ $line['position'] }}" name="lines[{{ $line['position'] }}]" inputmode="numeric" value="{{ old('lines.'.$line['position'], 0) }}" class="w-20 rounded-md border border-line px-3 py-2 text-center" aria-describedby="line-{{ $line['position'] }}-max">
                        <span id="line-{{ $line['position'] }}-max" class="sr-only">{{ __('shipping::returns.at_most', ['count' => $line['left']]) }}</span>
                    @endif
                </div>
            @endforeach
        </fieldset>
        <div>
            <label for="reason_code" class="block text-sm font-medium">{{ __('shipping::returns.why') }}</label>
            <select id="reason_code" name="reason_code" required class="mt-1 block w-full rounded-md border border-line px-3 py-2">
                <option value="">{{ __('shipping::returns.choose') }}</option>
                @foreach ($reasons as $code)<option value="{{ $code }}" @selected(old('reason_code') === $code)>{{ __('shipping::returns.reason.'.$code) }}</option>@endforeach
            </select>
            @error('reason_code')<p class="mt-1 text-sm text-danger">{{ __('shipping::returns.choose_reason') }}</p>@enderror
        </div>
        <div>
            <label for="note" class="block text-sm font-medium">{{ __('shipping::returns.note') }}</label>
            <textarea id="note" name="note" rows="3" maxlength="255" class="mt-1 block w-full rounded-md border border-line px-3 py-2">{{ old('note') }}</textarea>
        </div>
        <x-platform::form.button>{{ __('shipping::returns.send') }}</x-platform::form.button>
    </form>
</div>
@endsection
