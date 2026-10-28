@extends('layouts.admin')

@section('title', $category ? __('catalog::admin.edit') : __('catalog::admin.add'))

@section('content')
<div class="max-w-3xl space-y-6">
    <h1 class="text-2xl font-bold">{{ $category ? __('catalog::admin.categories.edit_title') : __('catalog::admin.categories.add_title') }}</h1>
    <x-platform::form.errors />

    <form method="POST" action="{{ $category ? route('admin.catalog.categories.update', $category) : route('admin.catalog.categories.store') }}" class="space-y-6" novalidate>
        @csrf
        @if ($category) @method('PUT') @endif

        <div class="space-y-1">
            <label for="parent" class="block text-sm font-medium">{{ __('catalog::admin.categories.parent') }}</label>
            <select id="parent" name="parent" class="block w-full rounded-md border border-line px-3 py-2">
                <option value="">{{ __('catalog::admin.categories.root') }}</option>
                @foreach ($parents as $option)
                    <option value="{{ $option['id'] }}" @selected(old('parent', $category?->parent?->public_id) === $option['id'])>{{ $option['label'] }}</option>
                @endforeach
            </select>
        </div>

        @include('catalog::admin.partials.translation-tabs', ['entity' => $category, 'fields' => ['name', 'slug', 'description', 'seo_title', 'seo_description']])

        <x-platform::form.field name="sort_order" type="number" inputmode="numeric" :value="$category?->sort_order ?? 0" :label="__('catalog::admin.fields.sort_order')" />
        <label class="flex items-center gap-2"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $category?->is_active ?? true)) class="size-4"> {{ __('catalog::admin.active') }}</label>

        <x-platform::form.button>{{ __('catalog::admin.save') }}</x-platform::form.button>
    </form>
</div>
@endsection
