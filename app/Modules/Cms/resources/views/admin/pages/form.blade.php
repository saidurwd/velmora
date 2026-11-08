@extends('layouts.admin')

@section('title', __('cms::admin.pages.title'))

@section('content')
<div class="max-w-4xl space-y-8">
    <h1 class="text-2xl font-bold">{{ $page ? __('cms::admin.pages.edit') : __('cms::admin.pages.add') }}</h1>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    <x-platform::form.errors />

    @if ($page)
        @php
            $live = $page->liveVersion?->number;
            $sched = $page->scheduledVersion;
            $latest = $draft?->number;
            $fmt = fn ($d) => \App\Modules\Platform\Support\Fmt::dateTime($d);
        @endphp
        <section aria-labelledby="pub-h" class="space-y-3 rounded-md border border-line p-4">
            <h2 id="pub-h" class="text-lg font-semibold">{{ __('cms::admin.publishing') }}</h2>
            @if ($page->kind !== null)<p class="text-sm">{{ __('cms::policies.page_is', ['kind' => __('cms::policies.kinds.'.$page->kind)]) }}</p>@endif
            <p>{{ __('cms::admin.status') }}: <strong>{{ __('cms::admin.statuses.'.$page->status) }}</strong>@if ($live) · {{ __('cms::admin.versions.number') }} {{ $live }}@endif</p>
            @if ($live === null)<p class="text-muted">{{ __('cms::admin.versions.never_published') }}</p>@endif
            @if ($latest !== null && $latest !== $live)<p class="text-warning">{{ __('cms::admin.versions.unpublished_changes', ['draft' => $latest, 'live' => $live ?? '—']) }}</p>@endif
            @if ($sched)<p>{{ __('cms::admin.versions.number') }} {{ $sched->number }}: {{ __('cms::admin.versions.scheduled', ['time' => $fmt($page->publish_at)]) }}</p>@endif
            @can('cms.publish')
                <form method="POST" action="{{ route('admin.cms.pages.transition', $page) }}" class="flex flex-wrap items-end gap-3">
                    @csrf
                    @if ($live !== null)<button name="action" value="unpublish" class="min-h-11 rounded-md border border-line px-4 py-2">{{ __('cms::admin.unpublish') }}</button>@endif
                    @if ($sched)<button name="action" value="cancel_schedule" class="min-h-11 rounded-md border border-line px-4 py-2">{{ __('cms::admin.versions.cancel_schedule') }}</button>@endif
                </form>
            @endcan
        </section>

        <section aria-labelledby="ver-h" class="space-y-3">
            <h2 id="ver-h" class="text-lg font-semibold">{{ __('cms::admin.versions.title') }}</h2>
            <table class="w-full text-sm">
                <caption class="sr-only">{{ __('cms::admin.versions.title') }}</caption>
                <thead><tr class="border-b border-line">
                    <th scope="col" class="py-2 text-start">{{ __('cms::admin.versions.number') }}</th>
                    <th scope="col" class="py-2 text-start">{{ __('cms::admin.fields.title') }}</th>
                    <th scope="col" class="py-2 text-start">{{ __('cms::admin.versions.saved_at') }}</th>
                    <th scope="col" class="py-2 text-start">{{ __('cms::admin.versions.state') }}</th>
                    <th scope="col" class="py-2 text-start">{{ __('cms::admin.actions') }}</th>
                </tr></thead>
                <tbody>
                @forelse ($versions as $v)
                    <tr class="border-b border-line align-top">
                        <td class="py-2">{{ $v->number }}</td>
                        <td class="py-2">{{ $v->title() ?: '—' }}</td>
                        <td class="py-2">{{ $fmt($v->created_at) }}</td>
                        <td class="py-2">
                            @if ($v->number === $live)<strong>{{ __('cms::admin.versions.live') }}</strong>@endif
                            @if ($sched?->number === $v->number) {{ __('cms::admin.versions.scheduled', ['time' => $fmt($page->publish_at)]) }}@endif
                            @if ($v->number === $latest) {{ __('cms::admin.versions.latest') }}@endif
                        </td>
                        <td class="space-y-2 py-2">
                            <a class="underline" href="{{ route('admin.cms.pages.preview', [$page, $v->number]) }}" target="_blank" rel="noopener">{{ __('cms::admin.versions.preview') }}</a>
                            @can('cms.publish')
                                @if ($v->number !== $live)
                                    <form method="POST" action="{{ route('admin.cms.pages.transition', $page) }}" class="flex flex-wrap items-end gap-2">
                                        @csrf
                                        <input type="hidden" name="version" value="{{ $v->number }}">
                                        @if ($page->kind !== null && auth('admin')->user()?->can('cms.policies.manage'))
                                            <fieldset class="text-xs"><legend class="font-medium">{{ __('cms::policies.acceptance.label') }}</legend>
                                                <label class="me-3"><input type="radio" name="acceptance_required" value="1" checked> {{ __('cms::policies.acceptance.yes') }}</label>
                                                <label><input type="radio" name="acceptance_required" value="0"> {{ __('cms::policies.acceptance.no') }}</label>
                                            </fieldset>
                                        @endif
                                        <button name="action" value="publish" class="min-h-11 rounded-md bg-brand-600 px-3 py-2 text-white">{{ $live !== null && $v->number < $live ? __('cms::admin.versions.rollback') : __('cms::admin.versions.publish') }}</button>
                                        <span><label for="publish_at_{{ $v->number }}" class="block text-xs font-medium">{{ __('cms::admin.publish_at') }}</label>
                                        <input id="publish_at_{{ $v->number }}" name="publish_at" type="datetime-local" class="rounded-md border border-line px-2 py-1"></span>
                                        <button name="action" value="schedule" class="min-h-11 rounded-md border border-line px-3 py-2">{{ __('cms::admin.versions.schedule') }}</button>
                                    </form>
                                @endif
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-4 text-center text-muted">{{ __('cms::admin.versions.none') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </section>
    @endif

    <form method="POST" action="{{ $page ? route('admin.cms.pages.update', $page) : route('admin.cms.pages.store') }}" class="space-y-6" novalidate>
        @csrf
        @if ($page)
            @method('PUT')
            <input type="hidden" name="base_version" value="{{ $draft?->number ?? 0 }}">
            <p class="text-sm text-muted">{{ __('cms::admin.versions.editing', ['number' => $draft?->number ?? 0, 'next' => ($draft?->number ?? 0) + 1]) }}</p>
        @endif
        <x-platform::translation-fields :translations="$draft?->translations" required="title" :fields="[
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
