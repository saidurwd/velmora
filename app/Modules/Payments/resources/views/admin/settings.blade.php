@extends('layouts.admin')

@section('title', __('payments::admin.settings_title'))

@php
    $dec = fn (int $minor) => \App\Modules\Payments\Support\ProviderAmount::toDecimal(\App\Modules\Platform\DTOs\Money::of($minor, 'BDT'));
    $when = fn (?string $v) => $v === null ? __('payments::admin.never') : \App\Modules\Platform\Support\Fmt::dateTime(\Carbon\CarbonImmutable::parse($v, 'UTC'));
@endphp

@section('content')
<div class="space-y-8">
    @include('payments::admin._alerts')
    <h1 class="text-2xl font-bold">{{ __('payments::admin.settings_title') }}</h1>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    @error('settings')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror

    <section aria-labelledby="freeze-h" class="space-y-3 rounded-lg border border-line p-4">
        <h2 id="freeze-h" class="text-lg font-semibold">{{ __('payments::admin.freeze_title') }}</h2>
        <p class="text-sm text-muted">{{ __('payments::admin.freeze_help') }}</p>
        <p><strong>{{ $frozen ? __('payments::admin.frozen_now') : __('payments::admin.running_now') }}</strong></p>
        @if ($can['freeze'])
        <form method="POST" action="{{ route('admin.payments.settings.freeze') }}">@csrf
            <input type="hidden" name="frozen" value="{{ $frozen ? 0 : 1 }}">
            <x-platform::btn type="submit" :variant="$frozen ? 'primary' : 'danger'">{{ $frozen ? __('payments::admin.resume_btn') : __('payments::admin.pause_btn') }}</x-platform::btn>
        </form>
        @endif
    </section>

    <form method="POST" action="{{ route('admin.payments.settings.update') }}" class="space-y-6">
        @csrf @method('PUT')
        <section aria-labelledby="limit-h" class="space-y-2">
            <h2 id="limit-h" class="text-lg font-semibold">{{ __('payments::admin.limit_title') }}</h2>
            <label for="refund_limit" class="block text-sm font-medium">{{ __('payments::admin.limit_label') }}</label>
            <input id="refund_limit" name="refund_limit" value="{{ old('refund_limit', $dec($refundLimit)) }}" inputmode="decimal" required @disabled(! $can['manage']) class="block w-48 rounded-md border border-line px-3 py-2">
            @error('refund_limit')<p class="text-sm text-danger">{{ $message }}</p>@enderror
        </section>

        <section aria-labelledby="methods-h" class="space-y-2">
            <h2 id="methods-h" class="text-lg font-semibold">{{ __('payments::admin.methods_title') }}</h2>
            @foreach ($methods as $m)
                <div class="flex items-center gap-3">
                    <input type="hidden" name="methods[{{ $m->code }}]" value="0" @disabled(! $can['manage'])>
                    <input id="m-{{ $m->id }}" type="checkbox" name="methods[{{ $m->code }}]" value="1" @checked(old('methods.'.$m->code, $m->is_enabled)) @disabled(! $can['manage'])>
                    <label for="m-{{ $m->id }}">{{ $m->translate('label', app()->getLocale()) ?? $m->code }} <span class="text-sm text-muted">({{ $m->code }}) — {{ __('payments::admin.method_enabled') }}</span></label>
                </div>
            @endforeach
        </section>
        @if ($can['manage'])<x-platform::btn type="submit">{{ __('payments::admin.save') }}</x-platform::btn>@endif
    </form>

    <section aria-labelledby="health-h" class="space-y-2">
        <h2 id="health-h" class="text-lg font-semibold">{{ __('payments::admin.health_title') }}</h2>
        <div class="overflow-x-auto"><table class="w-full text-sm">
            <caption class="sr-only">{{ __('payments::admin.health_title') }}</caption>
            <thead><tr class="border-b border-line">@foreach (['provider', 'setup', 'mode', 'last_paid', 'last_notification', 'open', 'errors', 'stuck', 'items'] as $col)<th scope="col" class="py-2 pe-3 text-start">{{ __('payments::admin.health_cols.'.$col) }}</th>@endforeach</tr></thead>
            <tbody>
            @foreach ($health as $h)
                <tr class="border-b border-line">
                    <th scope="row" class="py-2 pe-3 text-start">{{ $h['provider'] }}</th>
                    <td class="py-2 pe-3">{{ $h['configured'] ? __('payments::admin.yes') : __('payments::admin.not_set_up') }}</td>
                    <td class="py-2 pe-3">{{ $h['capabilities'] === null ? '—' : ($h['capabilities']->sandbox ? __('payments::admin.test_mode') : __('payments::admin.live_mode')) }}</td>
                    <td class="py-2 pe-3">{{ $when($h['last_paid_at']) }}</td><td class="py-2 pe-3">{{ $when($h['last_notification_at']) }}</td>
                    <td class="py-2 pe-3">{{ $h['open_attempts'] }}</td><td class="py-2 pe-3">{{ $h['notification_errors_24h'] }}</td><td class="py-2 pe-3">{{ $h['unprocessed_notifications'] }}</td><td class="py-2 pe-3">{{ $h['open_exceptions'] }}</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    </section>
</div>
@endsection
