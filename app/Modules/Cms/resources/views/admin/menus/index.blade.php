@extends('layouts.admin')

@section('title', __('cms::menu.title'))

@section('content')
<div class="max-w-4xl space-y-8">
    <h1 class="text-2xl font-bold">{{ __('cms::menu.title') }}</h1>
    <p class="text-muted">{{ __('cms::menu.intro') }}</p>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif

    @foreach ($menus as $menu => $tree)
        <section aria-labelledby="m-{{ $menu }}" class="space-y-3">
            <div class="flex items-center justify-between gap-4">
                <h2 id="m-{{ $menu }}" class="text-lg font-semibold">{{ __('cms::menu.menus.'.$menu) }}</h2>
                @can('cms.edit')<a href="{{ route('admin.cms.menus.create', ['menu' => $menu]) }}" class="inline-flex min-h-11 items-center rounded-md bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-700">{{ __('cms::menu.add') }}</a>@endcan
            </div>
            <p class="text-sm text-muted">{{ __('cms::menu.when_empty.'.$menu) }}</p>
            @if ($tree === [])
                <p class="text-muted">{{ __('cms::menu.empty') }}</p>
            @else
                <ul class="space-y-2">
                    @foreach ($tree as $node)
                        <li class="rounded-md border border-line p-3">
                            @include('cms::admin.menus.row', ['row' => $node['item']])
                            @if ($node['children'] !== [])
                                <ul class="mt-2 space-y-2 ps-6">
                                    @foreach ($node['children'] as $child)<li class="rounded-md border border-line p-2">@include('cms::admin.menus.row', ['row' => $child])</li>@endforeach
                                </ul>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endforeach
</div>
@endsection
