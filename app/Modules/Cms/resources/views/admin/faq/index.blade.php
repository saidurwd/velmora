@extends('layouts.admin')

@section('title', __('cms::faq.admin_title'))

@section('content')
<div class="max-w-4xl space-y-6">
    <h1 class="text-2xl font-bold">{{ __('cms::faq.admin_title') }}</h1>
    <p class="text-sm text-muted">{{ __('cms::faq.admin_help') }}</p>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    @can('cms.edit')<p><x-platform::btn :href="route('admin.cms.faq.create')">{{ __('cms::faq.new') }}</x-platform::btn></p>@endcan
    @if ($entries === [])
        <p class="text-muted">{{ __('cms::faq.none') }}</p>
    @else
        <ul class="divide-y divide-line rounded-lg border border-line">
            @foreach ($entries as $e)
                <li class="flex flex-wrap items-center justify-between gap-3 p-3">
                    <span><span class="text-sm text-muted">{{ __('cms::faq.category.'.$e['category']) }}</span> · {{ $e['question'] }} <x-platform::badge :tone="$e['published'] ? 'success' : 'neutral'">{{ $e['published'] ? __('cms::faq.shown') : __('cms::faq.hidden') }}</x-platform::badge></span>
                    <span class="flex gap-3">
                        <a class="underline" href="{{ route('admin.cms.faq.edit', $e['id']) }}">{{ __('cms::faq.edit') }}</a>
                        @can('cms.edit')<form method="POST" action="{{ route('admin.cms.faq.destroy', $e['id']) }}">@csrf @method('DELETE')<button type="submit" class="underline text-danger">{{ __('cms::faq.delete') }}</button></form>@endcan
                    </span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
@endsection
