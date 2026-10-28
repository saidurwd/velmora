@extends('layouts.admin')

@section('title', __('cms::admin.pages.title'))

@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between gap-4">
        <h1 class="text-2xl font-bold">{{ __('cms::admin.pages.title') }}</h1>
        @can('cms.edit')<a href="{{ route('admin.cms.pages.create') }}" class="inline-flex min-h-11 items-center rounded-md bg-brand-600 px-5 py-2 font-medium text-white hover:bg-brand-700">{{ __('cms::admin.add') }}</a>@endcan
    </div>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif

    <table class="w-full text-sm">
        <caption class="sr-only">{{ __('cms::admin.pages.title') }}</caption>
        <thead><tr class="border-b border-line">
            <th scope="col" class="py-2 text-start">{{ __('cms::admin.fields.title') }}</th>
            <th scope="col" class="py-2 text-start">{{ __('cms::admin.languages') }}</th>
            <th scope="col" class="py-2 text-start">{{ __('cms::admin.status') }}</th>
        </tr></thead>
        <tbody>
        @forelse ($pages as $page)
            <tr class="border-b border-line">
                <td class="py-2"><a class="underline" href="{{ route('admin.cms.pages.edit', $page) }}">{{ $page->translate('title') ?? '—' }}</a></td>
                <td class="py-2">{{ $page->translations->pluck('locale')->map(fn ($l) => strtoupper($l))->join(', ') }}</td>
                <td class="py-2">{{ __('cms::admin.statuses.'.$page->status) }}@if ($page->publish_at) · {{ \App\Modules\Platform\Support\Fmt::dateTime($page->publish_at) }}@endif</td>
            </tr>
        @empty
            <tr><td colspan="3" class="py-6 text-center text-muted">{{ __('cms::admin.empty') }}</td></tr>
        @endforelse
        </tbody>
    </table>
    {{ $pages->links() }}
</div>
@endsection
