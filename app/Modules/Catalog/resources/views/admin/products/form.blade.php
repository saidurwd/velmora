@extends('layouts.admin')

@section('title', $product ? __('catalog::admin.products.edit_title') : __('catalog::admin.products.add_title'))

@php
    $selectedCategories = old('categories', $product?->categories->pluck('public_id')->all() ?? []);
    $selectedTags = old('tags', $product?->tags->pluck('public_id')->all() ?? []);
    $primaryCategory = old('primary_category', $product?->categories->firstWhere('pivot.is_primary', true)?->public_id);
    $usedAttributes = $product ? $product->variants->flatMap(fn ($v) => $v->optionValues)->map(fn ($ov) => $ov->attribute)->unique('id')->values() : collect();
@endphp

@section('content')
<div class="max-w-4xl space-y-8">
    <h1 class="text-2xl font-bold">{{ $product ? __('catalog::admin.products.edit_title') : __('catalog::admin.products.add_title') }}</h1>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    <x-platform::form.errors />

    @if ($product)
        <section aria-labelledby="publish-h" class="space-y-3 rounded-md border border-line p-4">
            <h2 id="publish-h" class="text-lg font-semibold">{{ __('catalog::admin.products.publishing') }}</h2>
            <p>{{ __('catalog::admin.status') }}: <strong>{{ __('catalog::admin.products.statuses.'.$product->status->value) }}</strong>
                @if ($product->publish_at) · {{ __('catalog::admin.products.scheduled_for') }} {{ \App\Modules\Platform\Support\Fmt::dateTime($product->publish_at) }} @endif</p>
            @can('catalog.publish')
                <form method="POST" action="{{ route('admin.catalog.products.transition', $product) }}" class="flex flex-wrap items-end gap-3">
                    @csrf
                    <button name="action" value="publish" class="min-h-11 rounded-md bg-brand-600 px-4 py-2 text-white">{{ __('catalog::admin.products.publish') }}</button>
                    <button name="action" value="unpublish" class="min-h-11 rounded-md border border-line px-4 py-2">{{ __('catalog::admin.products.unpublish') }}</button>
                    <button name="action" value="archive" class="min-h-11 rounded-md border border-line px-4 py-2">{{ __('catalog::admin.products.archive') }}</button>
                    <span class="flex items-end gap-2">
                        <span><label for="publish_at" class="block text-sm font-medium">{{ __('catalog::admin.products.publish_at') }}</label>
                        <input id="publish_at" name="publish_at" type="datetime-local" class="rounded-md border border-line px-3 py-2"></span>
                        <button name="action" value="schedule" class="min-h-11 rounded-md border border-line px-4 py-2">{{ __('catalog::admin.products.schedule') }}</button>
                    </span>
                </form>
            @endcan
        </section>
    @endif

    <form method="POST" action="{{ $product ? route('admin.catalog.products.update', $product) : route('admin.catalog.products.store') }}" class="space-y-6" novalidate>
        @csrf
        @if ($product) @method('PUT') @endif

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="space-y-1">
                <label for="product_type" class="block text-sm font-medium">{{ __('catalog::admin.products.type') }}</label>
                <select id="product_type" name="product_type" class="block w-full rounded-md border border-line px-3 py-2">
                    @foreach ($types as $type)<option value="{{ $type->value }}" @selected(old('product_type', $product?->product_type->value) === $type->value)>{{ __('catalog::admin.products.types.'.$type->value) }}</option>@endforeach
                </select>
            </div>
            <div class="space-y-1">
                <label for="tax_class_code" class="block text-sm font-medium">{{ __('catalog::admin.products.tax_class') }}</label>
                <select id="tax_class_code" name="tax_class_code" class="block w-full rounded-md border border-line px-3 py-2">
                    @foreach ($taxClasses as $code)<option value="{{ $code }}" @selected(old('tax_class_code', $product?->tax_class_code ?? 'standard') === $code)>{{ $code }}</option>@endforeach
                </select>
            </div>
            <div class="space-y-1">
                <label for="brand" class="block text-sm font-medium">{{ __('catalog::admin.menu.brands') }}</label>
                <select id="brand" name="brand" class="block w-full rounded-md border border-line px-3 py-2">
                    <option value="">—</option>
                    @foreach ($brands as $brand)<option value="{{ $brand->public_id }}" @selected(old('brand', $product?->brand?->public_id) === $brand->public_id)>{{ $brand->translate('name') }}</option>@endforeach
                </select>
            </div>
            <div class="space-y-1">
                <label for="primary_category" class="block text-sm font-medium">{{ __('catalog::admin.products.primary_category') }}</label>
                <select id="primary_category" name="primary_category" class="block w-full rounded-md border border-line px-3 py-2">
                    <option value="">—</option>
                    @foreach ($categories as $c)<option value="{{ $c->public_id }}" @selected($primaryCategory === $c->public_id)>{{ $c->translate('name') }}</option>@endforeach
                </select>
            </div>
        </div>

        <fieldset class="space-y-2"><legend class="text-sm font-medium">{{ __('catalog::admin.menu.categories') }}</legend>
            <div class="grid gap-2 sm:grid-cols-3">
                @foreach ($categories as $c)
                    <label class="flex items-center gap-2"><input type="checkbox" name="categories[]" value="{{ $c->public_id }}" @checked(in_array($c->public_id, $selectedCategories, true)) class="size-4"> {{ $c->translate('name') }}</label>
                @endforeach
            </div>
        </fieldset>
        <fieldset class="space-y-2"><legend class="text-sm font-medium">{{ __('catalog::admin.menu.tags') }}</legend>
            <div class="grid gap-2 sm:grid-cols-3">
                @foreach ($tags as $t)
                    <label class="flex items-center gap-2"><input type="checkbox" name="tags[]" value="{{ $t->public_id }}" @checked(in_array($t->public_id, $selectedTags, true)) class="size-4"> {{ $t->translate('name') }}</label>
                @endforeach
            </div>
        </fieldset>

        @include('catalog::admin.partials.translation-tabs', ['entity' => $product, 'fields' => ['name', 'slug', 'short_description', 'description', 'seo_title', 'seo_description']])

        @unless ($product)
            <fieldset class="space-y-4 rounded-md border border-line p-4"><legend class="px-1 font-semibold">{{ __('catalog::admin.products.first_variant') }}</legend>
                <x-platform::form.field name="sku" :label="__('catalog::admin.variants.sku')" :help="__('catalog::admin.variants.sku_help')" required />
                <x-platform::form.field name="barcode" :label="__('catalog::admin.variants.barcode')" />
                <x-platform::form.field name="weight_g" type="number" inputmode="numeric" :label="__('catalog::admin.variants.weight')" />
                <label class="flex items-center gap-2"><input type="checkbox" name="track_inventory" value="1" @checked(old('track_inventory')) class="size-4"> {{ __('catalog::admin.variants.track') }}</label>
            </fieldset>
        @endunless

        <x-platform::form.button>{{ __('catalog::admin.save') }}</x-platform::form.button>
    </form>

    @if ($product)
        @include('catalog::admin.products.variants')
        @include('catalog::admin.products.media')
    @endif
</div>
@endsection
