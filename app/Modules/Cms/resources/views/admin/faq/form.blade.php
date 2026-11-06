@extends('layouts.admin')

@section('title', __('cms::faq.admin_title'))

@section('content')
<div class="max-w-3xl space-y-6">
    <p><a class="text-sm underline" href="{{ route('admin.cms.faq.index') }}">← {{ __('cms::faq.admin_title') }}</a></p>
    <h1 class="text-2xl font-bold">{{ $entry ? __('cms::faq.edit') : __('cms::faq.new') }}</h1>
    @error('faq')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror
    <form method="POST" action="{{ $entry ? route('admin.cms.faq.update', $entry['id']) : route('admin.cms.faq.store') }}" class="space-y-5" novalidate>
        @csrf @if ($entry) @method('PUT') @endif
        <div class="max-w-xs"><label for="category" class="block text-sm font-medium">{{ __('cms::faq.category_label') }}</label>
            <select id="category" name="category" class="mt-1 block w-full rounded-md border border-line px-3 py-2">@foreach ($categories as $c)<option value="{{ $c }}" @selected(old('category', $entry['category'] ?? 'orders') === $c)>{{ __('cms::faq.category.'.$c) }}</option>@endforeach</select></div>
        @foreach ($locales as $locale)
            <fieldset class="space-y-3 rounded-lg border border-line p-4">
                <legend class="px-1 font-medium">{{ strtoupper($locale) }}@if ($locale === config('app.default_locale')) <span class="text-sm text-muted">({{ __('cms::faq.required') }})</span>@endif</legend>
                <div><label for="q-{{ $locale }}" class="block text-sm font-medium">{{ __('cms::faq.question') }}</label><input id="q-{{ $locale }}" name="texts[{{ $locale }}][question]" maxlength="200" value="{{ old("texts.{$locale}.question", $entry['texts'][$locale]['question'] ?? '') }}" class="mt-1 block w-full rounded-md border border-line px-3 py-2"></div>
                <div><label for="a-{{ $locale }}" class="block text-sm font-medium">{{ __('cms::faq.answer') }}</label><textarea id="a-{{ $locale }}" name="texts[{{ $locale }}][answer]" rows="5" maxlength="4000" class="mt-1 block w-full rounded-md border border-line px-3 py-2">{{ old("texts.{$locale}.answer", $entry['texts'][$locale]['answer'] ?? '') }}</textarea></div>
            </fieldset>
        @endforeach
        <div class="max-w-xs"><label for="sort" class="block text-sm font-medium">{{ __('cms::faq.sort') }}</label><input id="sort" name="sort" inputmode="numeric" value="{{ old('sort', $entry['sort'] ?? 0) }}" class="mt-1 block w-full rounded-md border border-line px-3 py-2"></div>
        @can('cms.publish')
            <input type="hidden" name="published_present" value="1">
            <label class="flex items-center gap-2"><input type="checkbox" name="published" value="1" @checked(old('published', $entry['published'] ?? false)) class="size-4"> {{ __('cms::faq.show_on_help') }}</label>
        @endcan
        <x-platform::btn type="submit">{{ __('cms::faq.save') }}</x-platform::btn>
    </form>
</div>
@endsection
