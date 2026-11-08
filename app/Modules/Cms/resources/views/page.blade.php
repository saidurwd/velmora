@extends('layouts.storefront')

@section('title', $translation->seo_title ?: $translation->title)

@section('content')
@isset($preview)
<div role="status" class="mx-auto mb-4 max-w-3xl rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-warning">
    <strong>{{ __('cms::admin.preview.banner', ['number' => $preview['number']]) }}</strong>
    @foreach ($preview['locales'] as $l)
        @if ($l !== $preview['locale'])<a class="ms-3 underline" href="{{ route('admin.cms.pages.preview', ['page' => $preview['page'], 'number' => $preview['number'], 'locale' => $l]) }}">{{ strtoupper($l) }}</a>@endif
    @endforeach
</div>
@endisset
<article class="mx-auto max-w-3xl space-y-4">
    <h1 class="text-3xl font-bold">{{ $translation->title }}</h1>
    <div class="space-y-4 leading-relaxed [&_a]:text-brand-700 [&_a]:underline [&_h2]:mt-6 [&_h2]:text-xl [&_h2]:font-semibold [&_h3]:mt-4 [&_h3]:font-semibold [&_ol]:list-decimal [&_ol]:ps-6 [&_ul]:list-disc [&_ul]:ps-6">{!! $translation->body !!}</div>
</article>
@endsection
