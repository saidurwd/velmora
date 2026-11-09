@extends('layouts.admin')

@section('title', __('cms::menu.title'))

@section('content')
<div class="max-w-3xl space-y-6">
    <h1 class="text-2xl font-bold">{{ $item ? __('cms::menu.edit_item') : __('cms::menu.add_item') }}</h1>
    <x-platform::form.errors />

    <form method="POST" action="{{ $item ? route('admin.cms.menus.update', $item->public_id) : route('admin.cms.menus.store') }}" class="space-y-6" novalidate>
        @csrf
        @if ($item) @method('PUT') @endif

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="menu" class="block text-sm font-medium">{{ __('cms::menu.fields.menu') }}</label>
                @if ($item)
                    <input type="hidden" name="menu" value="{{ $menu }}"><p class="py-2">{{ __('cms::menu.menus.'.$menu) }}</p>
                @else
                    <select id="menu" name="menu" class="w-full rounded-md border border-line px-3 py-2">@foreach (['header', 'footer'] as $m)<option value="{{ $m }}" @selected(old('menu', $menu) === $m)>{{ __('cms::menu.menus.'.$m) }}</option>@endforeach</select>
                @endif
            </div>
            <div>
                <label for="kind" class="block text-sm font-medium">{{ __('cms::menu.fields.kind') }}</label>
                <select id="kind" name="kind" class="w-full rounded-md border border-line px-3 py-2">@foreach (['page', 'url', 'group'] as $k)<option value="{{ $k }}" @selected(old('kind', $item->kind ?? 'page') === $k)>{{ __('cms::menu.kinds.'.$k) }}</option>@endforeach</select>
                <p class="text-xs text-muted">{{ __('cms::menu.fields.kind_help') }}</p>
            </div>
            <div>
                <label for="page" class="block text-sm font-medium">{{ __('cms::menu.fields.page') }}</label>
                <select id="page" name="page" class="w-full rounded-md border border-line px-3 py-2"><option value="">—</option>@foreach ($pages as $p)<option value="{{ $p->public_id }}" @selected(old('page', $pagePublicId) === $p->public_id)>{{ $p->title }}</option>@endforeach</select>
                <p class="text-xs text-muted">{{ __('cms::menu.fields.page_help') }}</p>
            </div>
            <div>
                <label for="parent" class="block text-sm font-medium">{{ __('cms::menu.fields.parent') }}</label>
                <select id="parent" name="parent" class="w-full rounded-md border border-line px-3 py-2"><option value="">—</option>@foreach ($groups as $g)<option value="{{ $g->public_id }}" @selected(old('parent', $parentPublicId) === $g->public_id)>{{ __('cms::menu.menus.'.$g->menu) }}: {{ $g->label }}</option>@endforeach</select>
                <p class="text-xs text-muted">{{ __('cms::menu.fields.parent_help') }}</p>
            </div>
        </div>

        <x-platform::translation-fields group="texts" :translations="$texts" required="label" :fields="[
            'label' => ['label' => __('cms::menu.fields.label')],
            'url' => ['label' => __('cms::menu.fields.url')],
        ]" />
        <p class="text-xs text-muted">{{ __('cms::menu.fields.url_help') }}</p>

        @can('cms.publish')
            <input type="hidden" name="active_present" value="1">
            <label class="flex items-center gap-2"><input type="checkbox" name="active" value="1" @checked(old('active', $item->is_active ?? false))> {{ __('cms::menu.fields.active') }}</label>
        @else
            <p class="text-sm text-muted">{{ __('cms::menu.starts_hidden') }}</p>
        @endcan

        <x-platform::form.button>{{ __('cms::admin.save') }}</x-platform::form.button>
    </form>
</div>
@endsection
