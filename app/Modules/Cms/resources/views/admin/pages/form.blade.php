@extends('layouts.admin')

@section('title', __('cms::admin.pages.title'))

@section('content')
<div class="max-w-4xl space-y-8">
    <h1 class="text-2xl font-bold">{{ $page ? __('cms::admin.pages.edit') : __('cms::admin.pages.add') }}</h1>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    <x-platform::form.errors />

    @if ($page)
        <section aria-labelledby="pub-h" class="space-y-3 rounded-md border border-line p-4">
            <h2 id="pub-h" class="text-lg font-semibold">{{ __('cms::admin.publishing') }}</h2>
            <p>{{ __('cms::admin.status') }}: <strong>{{ __('cms::admin.statuses.'.$page->status) }}</strong>@if ($page->publish_at) · {{ __('cms::admin.scheduled_for') }} {{ \App\Modules\Platform\Support\Fmt::dateTime($page->publish_at) }}@endif</p>
            @can('cms.publish')
                <form method="POST" action="{{ route('admin.cms.pages.transition', $page) }}" class="flex flex-wrap items-end gap-3">
                    @csrf
                    <button name="action" value="publish" class="min-h-11 rounded-md bg-brand-600 px-4 py-2 text-white">{{ __('cms::admin.publish') }}</button>
                    <button name="action" value="unpublish" class="min-h-11 rounded-md border border-line px-4 py-2">{{ __('cms::admin.unpublish') }}</button>
                    <span class="flex items-end gap-2">
                        <span><label for="publish_at" class="block text-sm font-medium">{{ __('cms::admin.publish_at') }}</label>
                        <input id="publish_at" name="publish_at" type="datetime-local" class="rounded-md border border-line px-3 py-2"></span>
                        <button name="action" value="schedule" class="min-h-11 rounded-md border border-line px-4 py-2">{{ __('cms::admin.schedule') }}</button>
                    </span>
                </form>
            @endcan
        </section>
    @endif

    <form method="POST" action="{{ $page ? route('admin.cms.pages.update', $page) : route('admin.cms.pages.store') }}" class="space-y-6" novalidate>
        @csrf
        @if ($page) @method('PUT') @endif
        <x-platform::translation-fields :translations="$page?->translations" required="title" :fields="[
            'title' => ['label' => __('cms::admin.fields.title')],
            'slug' => ['label' => __('cms::admin.fields.slug')],
            'body' => ['label' => __('cms::admin.fields.body'), 'type' => 'textarea', 'rows' => 14],
            'seo_title' => ['label' => __('cms::admin.fields.seo_title')],
            'seo_description' => ['label' => __('cms::admin.fields.seo_description')],
        ]" />
        <x-platform::form.button>{{ __('cms::admin.save') }}</x-platform::form.button>
    </form>
</div>
@endsection
