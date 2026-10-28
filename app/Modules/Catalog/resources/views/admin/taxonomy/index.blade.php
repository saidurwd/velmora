@extends('layouts.admin')

@section('title', __('catalog::admin.'.$type.'.title'))

@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between gap-4">
        <h1 class="text-2xl font-bold">{{ __('catalog::admin.'.$type.'.title') }}</h1>
        @can('catalog.create')<a href="{{ route('admin.catalog.taxonomy.create', $type) }}" class="inline-flex min-h-11 items-center rounded-md bg-brand-600 px-5 py-2 font-medium text-white hover:bg-brand-700">{{ __('catalog::admin.add') }}</a>@endcan
    </div>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif

    <table class="w-full text-sm">
        <caption class="sr-only">{{ __('catalog::admin.'.$type.'.title') }}</caption>
        <thead><tr class="border-b border-line">
            <th scope="col" class="py-2 text-start">{{ __('catalog::admin.fields.name') }}</th>
            <th scope="col" class="py-2 text-start">{{ __('catalog::admin.languages') }}</th>
            <th scope="col" class="py-2 text-start">{{ __('catalog::admin.status') }}</th>
            <th scope="col" class="py-2"><span class="sr-only">{{ __('catalog::admin.actions') }}</span></th>
        </tr></thead>
        <tbody>
        @forelse ($items as $item)
            <tr class="border-b border-line">
                <td class="py-2">{{ $item->translate('name') }}</td>
                <td class="py-2">{{ $item->translations->pluck('locale')->map(fn ($l) => strtoupper($l))->join(', ') }}</td>
                <td class="py-2">{{ $item->is_active ? __('catalog::admin.active') : __('catalog::admin.inactive') }}</td>
                <td class="flex gap-3 py-2">
                    @can('catalog.update')<a class="underline" href="{{ route('admin.catalog.taxonomy.edit', [$type, $item->public_id]) }}">{{ __('catalog::admin.edit') }}</a>@endcan
                    @can('catalog.delete')
                        <form method="POST" action="{{ route('admin.catalog.taxonomy.destroy', [$type, $item->public_id]) }}" onsubmit="return confirm(@js(__('catalog::admin.confirm_delete')))">
                            @csrf @method('DELETE')
                            <button class="underline">{{ __('catalog::admin.delete') }}</button>
                        </form>
                    @endcan
                </td>
            </tr>
        @empty
            <tr><td colspan="4" class="py-6 text-center text-muted">{{ __('catalog::admin.empty') }}</td></tr>
        @endforelse
        </tbody>
    </table>
    {{ $items->links() }}
</div>
@endsection
