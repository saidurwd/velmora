@extends('layouts.storefront')

@section('title', __('cms::faq.help_title'))

@section('content')
<div class="max-w-3xl space-y-6">
    <h1 class="text-2xl font-bold">{{ __('cms::faq.help_title') }}</h1>
    <p>{{ __('cms::faq.help_intro') }}</p>
    <form method="GET" action="{{ route('help') }}" role="search" class="flex items-end gap-3">
        <div class="flex-1"><label for="q" class="block text-sm font-medium">{{ __('cms::faq.search_label') }}</label><input id="q" type="search" name="q" value="{{ $q }}" maxlength="80" class="mt-1 block w-full rounded-md border border-line px-3 py-2"></div>
        <x-platform::btn type="submit">{{ __('cms::faq.search_button') }}</x-platform::btn>
    </form>
    @if ($groups === [])
        <p class="text-muted">{{ $q === '' ? __('cms::faq.none') : __('cms::faq.no_match', ['q' => $q]) }}</p>
    @else
        @foreach ($categories as $category)
            @if (isset($groups[$category]))
                <section aria-labelledby="cat-{{ $category }}" class="space-y-2">
                    <h2 id="cat-{{ $category }}" class="text-lg font-semibold">{{ __('cms::faq.category.'.$category) }}</h2>
                    @foreach ($groups[$category] as $item)
                        <details class="rounded-lg border border-line p-3" @if ($q !== '') open @endif>
                            <summary class="cursor-pointer font-medium">{{ $item['question'] }}</summary>
                            <p class="mt-2 whitespace-pre-line">{{ $item['answer'] }}</p>
                        </details>
                    @endforeach
                </section>
            @endif
        @endforeach
    @endif
    <p class="border-t border-line pt-4">{{ __('cms::faq.still_stuck') }} <a class="underline" href="{{ route('contact') }}">{{ __('cms::faq.contact_link') }}</a></p>
</div>
@endsection
