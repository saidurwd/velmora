@extends('layouts.admin')

@section('title', __('cms::admin.banners.title'))

@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between gap-4">
        <h1 class="text-2xl font-bold">{{ __('cms::admin.banners.title') }}</h1>
        @can('cms.edit')<a href="{{ route('admin.cms.banners.create') }}" class="inline-flex min-h-11 items-center rounded-md bg-brand-600 px-5 py-2 font-medium text-white hover:bg-brand-700">{{ __('cms::admin.add') }}</a>@endcan
    </div>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif

    <table class="w-full text-sm">
        <caption class="sr-only">{{ __('cms::admin.banners.title') }}</caption>
        <thead><tr class="border-b border-line">
            <th scope="col" class="py-2 text-start">{{ __('cms::admin.fields.title') }}</th>
            <th scope="col" class="py-2 text-start">{{ __('cms::admin.banners.placement') }}</th>
            <th scope="col" class="py-2 text-start">{{ __('cms::admin.languages') }}</th>
            <th scope="col" class="py-2 text-start">{{ __('cms::admin.status') }}</th>
            <th scope="col" class="py-2"><span class="sr-only">{{ __('cms::admin.actions') }}</span></th>
        </tr></thead>
        <tbody>
        @forelse ($banners as $banner)
            <tr class="border-b border-line">
                <td class="py-2"><a class="underline" href="{{ route('admin.cms.banners.edit', $banner) }}">{{ $banner->translations->firstWhere('locale', config('app.default_locale'))?->title ?: '—' }}</a></td>
                <td class="py-2">{{ __('cms::admin.banners.placements.'.$banner->placement) }}</td>
                <td class="py-2">{{ $banner->translations->pluck('locale')->map(fn ($l) => strtoupper($l))->join(', ') }}</td>
                <td class="py-2">{{ $banner->is_active ? __('cms::admin.active') : __('cms::admin.inactive') }}</td>
                <td class="py-2">@can('cms.edit')
                    <form method="POST" action="{{ route('admin.cms.banners.destroy', $banner) }}" onsubmit="return confirm(@js(__('cms::admin.confirm_delete')))">@csrf @method('DELETE')<button class="underline">{{ __('cms::admin.delete') }}</button></form>
                @endcan</td>
            </tr>
        @empty
            <tr><td colspan="5" class="py-6 text-center text-muted">{{ __('cms::admin.empty') }}</td></tr>
        @endforelse
        </tbody>
    </table>
    {{ $banners->links() }}
</div>
@endsection
