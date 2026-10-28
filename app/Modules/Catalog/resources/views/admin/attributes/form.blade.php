@extends('layouts.admin')

@section('title', __('catalog::admin.attributes.title'))

@section('content')
<div class="max-w-3xl space-y-6">
    <h1 class="text-2xl font-bold">{{ __('catalog::admin.attributes.title') }}</h1>
    <x-platform::form.errors />

    <form method="POST" action="{{ $attribute ? route('admin.catalog.attributes.update', $attribute->code) : route('admin.catalog.attributes.store') }}" class="space-y-6" novalidate>
        @csrf
        @if ($attribute) @method('PUT') @endif

        <x-platform::form.field name="code" :value="$attribute?->code" :label="__('catalog::admin.attributes.code')" :readonly="(bool) $attribute" />

        <div class="space-y-1">
            <label for="kind" class="block text-sm font-medium">{{ __('catalog::admin.attributes.kind') }}</label>
            <select id="kind" name="kind" class="block w-full rounded-md border border-line px-3 py-2">
                @foreach (['option', 'spec'] as $kind)
                    <option value="{{ $kind }}" @selected(old('kind', $attribute?->kind) === $kind)>{{ __('catalog::admin.attributes.kinds.'.$kind) }}</option>
                @endforeach
            </select>
        </div>

        @foreach ((array) config('app.supported_locales') as $locale)
            <x-platform::form.field :name="'names['.$locale.']'" :value="old('names.'.$locale, $attribute?->translations->firstWhere('locale', $locale)?->name)" :label="__('catalog::admin.fields.name').' ('.strtoupper($locale).')'" lang="{{ $locale }}" />
        @endforeach

        <div class="space-y-1">
            <label for="values" class="block text-sm font-medium">{{ __('catalog::admin.attributes.values') }}</label>
            <p id="values-help" class="text-sm text-muted">{{ __('catalog::admin.attributes.values_help') }}</p>
            <textarea id="values" name="values" rows="8" aria-describedby="values-help" class="block w-full rounded-md border border-line px-3 py-2 font-mono">{{ old('values', $valueLines) }}</textarea>
        </div>

        <label class="flex items-center gap-2"><input type="checkbox" name="is_filterable" value="1" @checked(old('is_filterable', $attribute?->is_filterable ?? false)) class="size-4"> {{ __('catalog::admin.attributes.filterable') }}</label>

        <x-platform::form.button>{{ __('catalog::admin.save') }}</x-platform::form.button>
    </form>
</div>
@endsection
