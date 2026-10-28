@extends('layouts.storefront')

@section('title', $translation->seo_title ?: $translation->title)

@push('head')
    @if ($translation->seo_description)<meta name="description" content="{{ $translation->seo_description }}">@endif
@endpush

@section('content')
<article class="mx-auto max-w-3xl space-y-4">
    <h1 class="text-3xl font-bold">{{ $translation->title }}</h1>
    <div class="space-y-4 leading-relaxed [&_a]:text-brand-700 [&_a]:underline [&_h2]:mt-6 [&_h2]:text-xl [&_h2]:font-semibold [&_h3]:mt-4 [&_h3]:font-semibold [&_ol]:list-decimal [&_ol]:ps-6 [&_ul]:list-disc [&_ul]:ps-6">{!! $translation->body !!}</div>
</article>
@endsection
