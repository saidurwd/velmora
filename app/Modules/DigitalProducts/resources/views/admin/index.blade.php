@extends('layouts.admin')

@section('title', __('digital-products::admin.title'))

@section('content')
<div class="max-w-4xl space-y-6">
    <h1 class="text-2xl font-bold">{{ __('digital-products::admin.title') }}</h1>
    <p class="text-sm text-muted">{{ __('digital-products::admin.help') }}</p>
    <form method="GET" action="{{ route('admin.digital.assets.index') }}" class="flex items-end gap-3">
        <div class="flex-1"><label for="q" class="block text-sm font-medium">{{ __('digital-products::admin.search') }}</label><input id="q" name="q" value="{{ $term }}" maxlength="80" class="block w-full rounded-md border border-line px-3 py-2"></div>
        <x-platform::btn type="submit" size="sm">{{ __('digital-products::admin.find') }}</x-platform::btn>
    </form>
    @if ($term !== '')
        @if ($variants === [])
            <p class="text-muted">{{ __('digital-products::admin.none_found') }}</p>
        @else
        <div class="overflow-x-auto"><table class="w-full text-sm">
            <caption class="sr-only">{{ __('digital-products::admin.title') }}</caption>
            <thead><tr class="border-b border-line">@foreach (['sku', 'product', 'versions'] as $col)<th scope="col" class="py-2 pe-3 text-start">{{ __('digital-products::admin.cols.'.$col) }}</th>@endforeach</tr></thead>
            <tbody>
            @foreach ($variants as $v)
                <tr class="border-b border-line"><td class="py-2 pe-3"><a class="underline" href="{{ route('admin.digital.assets.show', $v->sku) }}">{{ $v->sku }}</a></td><td class="py-2 pe-3">{{ $v->productName }}</td><td class="py-2 pe-3">{{ $assets[$v->id]->versions_count ?? 0 }}</td></tr>
            @endforeach
            </tbody>
        </table></div>
        @endif
    @endif
</div>
@endsection
