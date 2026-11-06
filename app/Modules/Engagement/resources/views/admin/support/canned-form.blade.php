@extends('layouts.admin')

@section('title', __('engagement::support.canned_title'))

@section('content')
<div class="max-w-3xl space-y-6">
    <p><a class="text-sm underline" href="{{ route('admin.support.canned.index') }}">← {{ __('engagement::support.canned_title') }}</a></p>
    <h1 class="text-2xl font-bold">{{ $reply ? __('engagement::support.edit') : __('engagement::support.canned_new') }}</h1>
    <p class="text-sm text-muted">{{ __('engagement::support.placeholders_help') }}</p>
    @error('canned')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror
    <form method="POST" action="{{ $reply ? route('admin.support.canned.update', $reply['id']) : route('admin.support.canned.store') }}" class="space-y-5" novalidate>
        @csrf @if ($reply) @method('PUT') @endif
        @foreach ($locales as $locale)
            <fieldset class="space-y-3 rounded-lg border border-line p-4">
                <legend class="px-1 font-medium">{{ strtoupper($locale) }}@if ($locale === config('app.default_locale')) <span class="text-sm text-muted">({{ __('engagement::support.required') }})</span>@endif</legend>
                <div><label for="title-{{ $locale }}" class="block text-sm font-medium">{{ __('engagement::support.title_label') }}</label><input id="title-{{ $locale }}" name="texts[{{ $locale }}][title]" maxlength="80" value="{{ old("texts.{$locale}.title", $reply['texts'][$locale]['title'] ?? '') }}" class="mt-1 block w-full rounded-md border border-line px-3 py-2"></div>
                <div><label for="body-{{ $locale }}" class="block text-sm font-medium">{{ __('engagement::support.body_label_canned') }}</label><textarea id="body-{{ $locale }}" name="texts[{{ $locale }}][body]" rows="5" maxlength="2000" class="mt-1 block w-full rounded-md border border-line px-3 py-2">{{ old("texts.{$locale}.body", $reply['texts'][$locale]['body'] ?? '') }}</textarea></div>
            </fieldset>
        @endforeach
        <label class="flex items-center gap-2"><input type="checkbox" name="active" value="1" @checked(old('active', $reply['active'] ?? true)) class="size-4"> {{ __('engagement::support.active') }}</label>
        <div class="max-w-xs"><label for="sort" class="block text-sm font-medium">{{ __('engagement::support.sort') }}</label><input id="sort" name="sort" inputmode="numeric" value="{{ old('sort', $reply['sort'] ?? 0) }}" class="mt-1 block w-full rounded-md border border-line px-3 py-2"></div>
        <x-platform::btn type="submit">{{ __('engagement::support.save') }}</x-platform::btn>
    </form>
</div>
@endsection
