@extends('layouts.account')

@section('title', __('notifications::account.title'))

@section('account')
<div class="max-w-xl space-y-6">
    <h1 class="text-2xl font-bold">{{ __('notifications::account.title') }}</h1>
    <p>{{ __('notifications::account.intro') }}</p>

    <form method="POST" action="{{ route('account.preferences.update') }}" class="space-y-6" novalidate>
        @csrf @method('PUT')
        <fieldset class="space-y-3">
            <legend class="font-semibold">{{ __('notifications::account.marketing') }}</legend>
            @foreach (['email', 'sms'] as $channel)
                <label class="flex items-start gap-2">
                    <input type="checkbox" name="{{ $channel }}" value="1" @checked(old($channel, $prefs['optIn'][$channel])) class="mt-1 size-4">
                    <span>{{ __('notifications::account.channel_'.$channel) }}</span>
                </label>
            @endforeach
            <p class="text-sm text-muted">{{ __('notifications::account.orders_always') }}</p>
        </fieldset>

        <fieldset class="space-y-3">
            <legend class="font-semibold">{{ __('notifications::account.quiet') }}</legend>
            <p class="text-sm text-muted">{{ __('notifications::account.quiet_help') }}</p>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-platform::form.field name="quiet_from" type="time" :value="$prefs['quietFrom']" :label="__('notifications::account.quiet_from')" />
                <x-platform::form.field name="quiet_to" type="time" :value="$prefs['quietTo']" :label="__('notifications::account.quiet_to')" />
            </div>
        </fieldset>

        <x-platform::form.button>{{ __('notifications::account.save') }}</x-platform::form.button>
    </form>

    @if ($prefs['history'] !== [])
        <section class="border-t border-line pt-6">
            <h2 class="font-semibold">{{ __('notifications::account.history') }}</h2>
            <ul class="mt-2 space-y-1 text-sm">
                @foreach ($prefs['history'] as $event)
                    <li>{{ \App\Modules\Platform\Support\Fmt::dateTime(\Carbon\CarbonImmutable::parse($event['at'], 'UTC')) }} — {{ __('notifications::account.channel_'.$event['channel']) }}: {{ $event['granted'] ? __('notifications::account.said_yes') : __('notifications::account.said_no') }}</li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
@endsection
