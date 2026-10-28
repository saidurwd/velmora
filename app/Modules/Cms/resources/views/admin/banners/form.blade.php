@extends('layouts.admin')

@section('title', __('cms::admin.banners.title'))

@php
    $zone = config('app.display_timezone');
    $local = fn ($t) => $t?->setTimezone($zone)->format('Y-m-d\TH:i');
@endphp

@section('content')
<div class="max-w-3xl space-y-6">
    <h1 class="text-2xl font-bold">{{ $banner ? __('cms::admin.banners.edit') : __('cms::admin.banners.add') }}</h1>
    <x-platform::form.errors />

    <form method="POST" action="{{ $banner ? route('admin.cms.banners.update', $banner) : route('admin.cms.banners.store') }}" enctype="multipart/form-data" class="space-y-6" novalidate>
        @csrf
        @if ($banner) @method('PUT') @endif

        <x-platform::form.select name="placement" :label="__('cms::admin.banners.placement')" :value="$banner?->placement" :options="collect(\App\Modules\Cms\Actions\SaveBanner::PLACEMENTS)->mapWithKeys(fn ($p) => [$p => __('cms::admin.banners.placements.'.$p)])->all()" />
        <x-platform::form.field name="link_url" :value="$banner?->link_url" :label="__('cms::admin.banners.link')" :help="__('cms::admin.banners.link_help')" />

        <x-platform::translation-fields :translations="$banner?->translations" :fields="[
            'title' => ['label' => __('cms::admin.fields.title')],
            'subtitle' => ['label' => __('cms::admin.banners.subtitle')],
            'cta_label' => ['label' => __('cms::admin.banners.cta')],
            'alt' => ['label' => __('cms::admin.banners.alt')],
        ]" />

        <fieldset class="space-y-3"><legend class="text-sm font-medium">{{ __('cms::admin.banners.images') }}</legend>
            <p class="text-sm text-muted">{{ __('cms::admin.banners.images_help') }}</p>
            @foreach ((array) config('app.supported_locales') as $locale)
                <div class="space-y-1">
                    <label for="img-{{ $locale }}" class="block text-sm">{{ strtoupper($locale) }}@if ($banner?->translations->firstWhere('locale', $locale)?->renditions) · {{ __('cms::admin.banners.has_image') }}@endif</label>
                    <input id="img-{{ $locale }}" name="images[{{ $locale }}]" type="file" accept="image/jpeg,image/png,image/webp" class="block w-full">
                </div>
            @endforeach
        </fieldset>

        <div class="grid gap-4 sm:grid-cols-2">
            <x-platform::form.field name="starts_local" type="datetime-local" :value="$local($banner?->starts_at)" :label="__('cms::admin.banners.starts')" :help="__('cms::admin.banners.dhaka')" />
            <x-platform::form.field name="ends_local" type="datetime-local" :value="$local($banner?->ends_at)" :label="__('cms::admin.banners.ends')" :help="__('cms::admin.banners.dhaka')" />
        </div>
        <x-platform::form.field name="sort_order" type="number" inputmode="numeric" :value="$banner?->sort_order ?? 0" :label="__('cms::admin.fields.sort_order')" />
        <x-platform::form.checkbox name="is_active" :label="__('cms::admin.active')" :checked="$banner?->is_active ?? true" />

        <x-platform::form.button>{{ __('cms::admin.save') }}</x-platform::form.button>
    </form>
</div>
@endsection
