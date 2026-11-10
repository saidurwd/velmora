@extends('layouts.admin')

@section('title', __('platform::integrations.title'))

@section('content')
<div class="max-w-3xl space-y-8">
    <h1 class="text-2xl font-bold">{{ __('platform::integrations.title') }}</h1>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    @if (session('test_ok'))<x-platform::alert type="success">{{ session('test_ok') }}</x-platform::alert>@endif
    @if (session('test_bad'))<x-platform::alert type="warning">{{ session('test_bad') }}</x-platform::alert>@endif
    <x-platform::alert type="info">{{ __('platform::integrations.intro') }}</x-platform::alert>
    <x-platform::form.errors />

    <dl class="grid gap-2 rounded-md border border-line p-4 text-sm sm:grid-cols-2">
        <div><dt class="text-muted">{{ __('platform::integrations.server.sandbox') }}</dt><dd class="font-medium">{{ $server['sandbox'] ? __('platform::integrations.server.on') : __('platform::integrations.server.off') }}</dd></div>
        <div><dt class="text-muted">{{ __('platform::integrations.server.live_allowed') }}</dt><dd class="font-medium">{{ $server['live_allowed'] ? __('platform::integrations.server.on') : __('platform::integrations.server.off') }}</dd></div>
        <div><dt class="text-muted">{{ __('platform::integrations.server.sms_driver') }}</dt><dd class="font-medium">{{ $server['sms_driver'] }}</dd></div>
        <div><dt class="text-muted">{{ __('platform::integrations.server.mailer') }}</dt><dd class="font-medium">{{ $server['mailer'] }}</dd></div>
        <p class="text-muted sm:col-span-2">{{ __('platform::integrations.server.note') }}</p>
    </dl>

    @foreach ($providers as $provider => $card)
        <section class="space-y-4 rounded-md border border-line p-4" id="{{ $provider }}">
            <h2 class="text-lg font-semibold">{{ __('platform::integrations.providers.'.$provider.'.title') }}</h2>
            <p class="text-sm text-muted">{{ __('platform::integrations.providers.'.$provider.'.about') }}</p>

            <form method="POST" action="{{ route('admin.integrations.update', $provider) }}" class="space-y-4" autocomplete="off" novalidate>
                @csrf @method('PUT')
                <input type="hidden" name="version" value="{{ $card['version'] }}">
                @foreach ($card['fields'] as $name => $f)
                    <div class="space-y-1">
                        <label for="{{ $provider }}-{{ $name }}" class="block font-medium">{{ __('platform::integrations.fields.'.$provider.'.'.$name) }}</label>
                        @if ($f['secret'])
                            <input id="{{ $provider }}-{{ $name }}" type="password" name="f[{{ $name }}]" value="" autocomplete="new-password" class="w-full rounded-md border border-line px-3 py-2" placeholder="{{ $f['display'] ?? '' }}">
                            <p class="text-sm text-muted">{{ __('platform::integrations.source.'.$f['source']) }} {{ __('platform::integrations.secret_help') }}</p>
                            @if ($f['source'] === 'saved' || $f['source'] === 'unreadable')
                                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="remove[{{ $name }}]" value="1" class="size-4"> {{ __('platform::integrations.remove') }}</label>
                            @endif
                        @elseif ($f['choices'])
                            <select id="{{ $provider }}-{{ $name }}" name="f[{{ $name }}]" class="w-full rounded-md border border-line px-3 py-2">
                                @foreach ($f['choices'] as $choice)<option value="{{ $choice }}" @selected(old('f.'.$name, $f['value']) === $choice)>{{ $choice }}</option>@endforeach
                            </select>
                            <p class="text-sm text-muted">{{ __('platform::integrations.source.'.$f['source']) }}</p>
                        @else
                            <input id="{{ $provider }}-{{ $name }}" type="text" name="f[{{ $name }}]" value="{{ old('f.'.$name, $f['value']) }}" class="w-full rounded-md border border-line px-3 py-2">
                            <p class="text-sm text-muted">{{ __('platform::integrations.source.'.$f['source']) }}</p>
                        @endif
                    </div>
                @endforeach
                <div class="space-y-1">
                    <label for="{{ $provider }}-password" class="block font-medium">{{ __('platform::integrations.your_password') }}</label>
                    <input id="{{ $provider }}-password" type="password" name="password" autocomplete="current-password" required class="w-full rounded-md border border-line px-3 py-2">
                </div>
                <x-platform::form.button>{{ __('platform::integrations.save') }}</x-platform::form.button>
            </form>

            @if ($card['canTest'])
                <form method="POST" action="{{ route('admin.integrations.test', $provider) }}">
                    @csrf
                    <button type="submit" class="rounded-md border border-line px-3 py-2 text-sm hover:bg-surface-alt">{{ __('platform::integrations.test') }}</button>
                    <span class="text-sm text-muted">{{ __('platform::integrations.providers.'.$provider.'.test_note') }}</span>
                </form>
            @endif
        </section>
    @endforeach
</div>
@endsection
