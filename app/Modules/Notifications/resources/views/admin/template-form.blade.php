@extends('layouts.admin')

@section('title', $code)

@php
    $def = $t['definition'];
    $email = $def->channel === 'email';
@endphp

@section('content')
<div class="max-w-4xl space-y-8">
    <header class="space-y-1">
        <h1 class="text-2xl font-bold">{{ $code }}</h1>
        <p class="text-sm text-muted">{{ __('notifications::admin.channels.'.$def->channel) }} · {{ __('notifications::admin.kinds.'.$def->kind) }}</p>
        <p><a href="{{ route('admin.notifications.templates') }}" class="inline-flex min-h-11 items-center underline">{{ __('notifications::admin.back') }}</a></p>
    </header>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    @error('template')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror

    <section aria-labelledby="vars-h" class="space-y-1 rounded-lg border border-line p-4 text-sm">
        <h2 id="vars-h" class="font-semibold">{{ __('notifications::admin.variables') }}</h2>
        <p class="text-muted">{{ __('notifications::admin.variables_help') }}</p>
        <ul class="flex flex-wrap gap-x-4 gap-y-1">
            @foreach ($def->variables as $v)<li><code>{!! e('{{ '.$v.' }}') !!}</code>@if (isset($def->sample[$v])) <span class="text-muted">({{ __('notifications::admin.example') }}: {{ $def->sample[$v] }})</span>@endif</li>@endforeach
        </ul>
    </section>

    @if ($preview)
    <section aria-labelledby="prev-h" class="space-y-2 rounded-lg border border-line p-4">
        <h2 id="prev-h" class="font-semibold">{{ __('notifications::admin.preview') }} ({{ strtoupper($preview['locale']) }})</h2>
        @if ($preview['problem'])<p class="text-danger" role="alert">{{ $preview['problem'] }}</p>@endif
        @if ($email && $preview['subject'] !== null)<p><strong>{{ __('notifications::admin.subject') }}:</strong> {{ $preview['subject'] }}</p>@endif
        <p class="whitespace-pre-line rounded-md bg-surface-alt p-3">{{ $preview['body'] }}</p>
        <p class="text-sm text-muted">{{ __('notifications::admin.preview_note') }}</p>
    </section>
    @endif

    @foreach ($t['locales'] as $locale)
        @php $w = $t['words'][$locale] ?? null; $isPreview = $preview && $preview['locale'] === $locale; @endphp
        <form method="POST" action="{{ route('admin.notifications.templates.save', ['code' => $code]) }}" class="space-y-3 rounded-lg border border-line p-4">
            @csrf @method('PUT')
            <input type="hidden" name="locale" value="{{ $locale }}">
            <h2 class="font-semibold">{{ strtoupper($locale) }}@if ($w) <span class="text-sm font-normal text-muted">— {{ __('notifications::admin.version', ['n' => $w->version]) }}</span>@else <span class="text-sm font-normal text-muted">— {{ __('notifications::admin.no_words_yet') }}</span>@endif</h2>
            @if ($email)
            <div><label for="s-{{ $locale }}" class="block text-sm font-medium">{{ __('notifications::admin.subject') }}</label>
                <input id="s-{{ $locale }}" name="subject" maxlength="150" value="{{ old('subject', $isPreview ? request('subject') : ($w->subject ?? '')) }}" @disabled(! $can) class="mt-1 block w-full rounded-md border border-line px-3 py-2"></div>
            @endif
            <div><label for="b-{{ $locale }}" class="block text-sm font-medium">{{ __('notifications::admin.words') }}</label>
                <textarea id="b-{{ $locale }}" name="body" rows="{{ $email ? 8 : 4 }}" maxlength="9000" @disabled(! $can) class="mt-1 block w-full rounded-md border border-line px-3 py-2">{{ old('body', $isPreview ? request('body') : ($w->body ?? '')) }}</textarea></div>
            <label class="flex items-center gap-2"><input type="hidden" name="active" value="0"><input type="checkbox" name="active" value="1" @checked($t['active']) @disabled(! $can) class="h-5 w-5">{{ __('notifications::admin.is_on') }}</label>
            @if ($can)
            <div class="flex flex-wrap gap-3">
                <x-platform::btn type="submit">{{ __('notifications::admin.save') }}</x-platform::btn>
                <button type="submit" formaction="{{ route('admin.notifications.templates.preview', ['code' => $code]) }}" class="inline-flex min-h-11 items-center rounded-md border border-line px-5 py-2">{{ __('notifications::admin.preview_button') }}</button>
            </div>
            @endif
        </form>
    @endforeach

    <section aria-labelledby="hist-h" class="space-y-2">
        <h2 id="hist-h" class="font-semibold">{{ __('notifications::admin.history') }}</h2>
        <ol class="space-y-2 text-sm">
            @foreach ($t['history'] as $h)
            <li class="rounded-md border border-line p-3"><strong>{{ strtoupper($h['locale']) }} · {{ __('notifications::admin.version', ['n' => $h['version']]) }}</strong> — {{ \App\Modules\Platform\Support\Fmt::dateTime(\Carbon\CarbonImmutable::parse($h['created_at'], 'UTC')) }}@if ($h['by']) · {{ $h['by'] }}@endif
                @if ($h['subject'])<div class="text-muted">{{ $h['subject'] }}</div>@endif<div class="whitespace-pre-line text-muted">{{ $h['body'] }}</div></li>
            @endforeach
        </ol>
    </section>
</div>
@endsection
