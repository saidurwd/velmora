@extends('layouts.admin')

@section('title', __('cms::home.title'))

@section('content')
<div class="max-w-3xl space-y-6">
    <h1 class="text-2xl font-bold">{{ $section ? __('cms::home.edit_section') : __('cms::home.add_section') }}</h1>
    <x-platform::form.errors />

    <form method="POST" action="{{ $section ? route('admin.cms.home.update', $section->public_id) : route('admin.cms.home.store') }}" class="space-y-6" novalidate>
        @csrf
        @if ($section) @method('PUT') @endif

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="type" class="block text-sm font-medium">{{ __('cms::home.fields.type') }}</label>
                @if ($section)
                    <input type="hidden" name="type" value="{{ $section->type }}"><p class="py-2">{{ __('cms::home.types.'.$section->type) }}</p>
                @else
                    <select id="type" name="type" class="w-full rounded-md border border-line px-3 py-2">@foreach (\App\Modules\Cms\Actions\SaveHomeSection::TYPES as $t)<option value="{{ $t }}" @selected(old('type') === $t)>{{ __('cms::home.types.'.$t) }}</option>@endforeach</select>
                    <p class="text-xs text-muted">{{ __('cms::home.fields.type_help') }}</p>
                @endif
            </div>
            <div>
                <label for="collection" class="block text-sm font-medium">{{ __('cms::home.fields.collection') }}</label>
                <select id="collection" name="collection" class="w-full rounded-md border border-line px-3 py-2"><option value="">—</option>@foreach ($collections as $id => $name)<option value="{{ $id }}" @selected(old('collection', $section->collection_ref ?? '') === $id)>{{ $name }}</option>@endforeach</select>
                <p class="text-xs text-muted">{{ __('cms::home.fields.collection_help') }}</p>
            </div>
        </div>

        <x-platform::translation-fields group="texts" :translations="$texts" :fields="[
            'title' => ['label' => __('cms::home.fields.title')],
            'body' => ['label' => __('cms::home.fields.body'), 'type' => 'textarea', 'rows' => 8],
        ]" />
        <p class="text-xs text-muted">{{ __('cms::home.fields.body_help', ['max' => \App\Modules\Cms\Actions\SaveHomeSection::BODY_MAX]) }}</p>

        <div class="grid gap-4 sm:grid-cols-2">
            <div><label for="starts_local" class="block text-sm font-medium">{{ __('cms::home.fields.starts') }}</label><input id="starts_local" name="starts_local" type="datetime-local" value="{{ old('starts_local', $startsLocal) }}" class="w-full rounded-md border border-line px-3 py-2"></div>
            <div><label for="ends_local" class="block text-sm font-medium">{{ __('cms::home.fields.ends') }}</label><input id="ends_local" name="ends_local" type="datetime-local" value="{{ old('ends_local', $endsLocal) }}" class="w-full rounded-md border border-line px-3 py-2"></div>
        </div>
        <p class="text-xs text-muted">{{ __('cms::home.fields.window_help') }}</p>

        @can('cms.publish')
            <input type="hidden" name="active_present" value="1">
            <label class="flex items-center gap-2"><input type="checkbox" name="active" value="1" @checked(old('active', $section->is_active ?? false))> {{ __('cms::home.fields.active') }}</label>
        @else
            <p class="text-sm text-muted">{{ __('cms::home.starts_hidden') }}</p>
        @endcan

        <x-platform::form.button>{{ __('cms::admin.save') }}</x-platform::form.button>
    </form>
</div>
@endsection
