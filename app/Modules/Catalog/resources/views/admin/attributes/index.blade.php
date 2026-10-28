@extends('layouts.admin')

@section('title', __('catalog::admin.attributes.title'))

@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between gap-4">
        <h1 class="text-2xl font-bold">{{ __('catalog::admin.attributes.title') }}</h1>
        @can('catalog.create')<a href="{{ route('admin.catalog.attributes.create') }}" class="inline-flex min-h-11 items-center rounded-md bg-brand-600 px-5 py-2 font-medium text-white hover:bg-brand-700">{{ __('catalog::admin.add') }}</a>@endcan
    </div>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif

    <table class="w-full text-sm">
        <caption class="sr-only">{{ __('catalog::admin.attributes.title') }}</caption>
        <thead><tr class="border-b border-line">
            <th scope="col" class="py-2 text-start">{{ __('catalog::admin.fields.name') }}</th>
            <th scope="col" class="py-2 text-start">{{ __('catalog::admin.attributes.code') }}</th>
            <th scope="col" class="py-2 text-start">{{ __('catalog::admin.attributes.kind') }}</th>
            <th scope="col" class="py-2 text-start">{{ __('catalog::admin.attributes.values') }}</th>
            <th scope="col" class="py-2"><span class="sr-only">{{ __('catalog::admin.actions') }}</span></th>
        </tr></thead>
        <tbody>
        @forelse ($attributes as $attribute)
            <tr class="border-b border-line">
                <td class="py-2">{{ $attribute->label() }}</td>
                <td class="py-2">{{ $attribute->code }}</td>
                <td class="py-2">{{ __('catalog::admin.attributes.kinds.'.$attribute->kind) }}</td>
                <td class="py-2">{{ \App\Modules\Platform\Support\Fmt::number($attribute->values_count) }}</td>
                <td class="py-2">@can('catalog.update')<a class="underline" href="{{ route('admin.catalog.attributes.edit', $attribute->code) }}">{{ __('catalog::admin.edit') }}</a>@endcan</td>
            </tr>
        @empty
            <tr><td colspan="5" class="py-6 text-center text-muted">{{ __('catalog::admin.empty') }}</td></tr>
        @endforelse
        </tbody>
    </table>
    {{ $attributes->links() }}
</div>
@endsection
