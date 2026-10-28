@extends('layouts.admin')

@section('title', __('catalog::admin.'.$type.'.title'))

@section('content')
<div class="max-w-3xl space-y-6">
    <h1 class="text-2xl font-bold">{{ __('catalog::admin.'.$type.'.title') }}</h1>
    <x-platform::form.errors />

    <form method="POST" action="{{ $item ? route('admin.catalog.taxonomy.update', [$type, $item->public_id]) : route('admin.catalog.taxonomy.store', $type) }}" class="space-y-6" novalidate>
        @csrf
        @if ($item) @method('PUT') @endif

        @include('catalog::admin.partials.translation-tabs', ['entity' => $item, 'fields' => ['name', 'slug', 'description', 'seo_title', 'seo_description']])

        <x-platform::form.field name="sort_order" type="number" inputmode="numeric" :value="$item?->sort_order ?? 0" :label="__('catalog::admin.fields.sort_order')" />
        <label class="flex items-center gap-2"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $item?->is_active ?? true)) class="size-4"> {{ __('catalog::admin.active') }}</label>

        <x-platform::form.button>{{ __('catalog::admin.save') }}</x-platform::form.button>
    </form>
</div>
@endsection
