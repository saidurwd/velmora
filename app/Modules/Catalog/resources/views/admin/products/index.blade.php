@extends('layouts.admin')

@section('title', __('catalog::admin.products.title'))

@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between gap-4">
        <h1 class="text-2xl font-bold">{{ __('catalog::admin.products.title') }}</h1>
        @can('catalog.create')<a href="{{ route('admin.catalog.products.create') }}" class="inline-flex min-h-11 items-center rounded-md bg-brand-600 px-5 py-2 font-medium text-white hover:bg-brand-700">{{ __('catalog::admin.add') }}</a>@endcan
    </div>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif

    <form method="GET" role="search" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
        <div class="lg:col-span-2"><label for="q" class="block text-sm font-medium">{{ __('catalog::admin.products.search') }}</label>
            <input id="q" name="q" value="{{ $filters['q'] ?? '' }}" class="block w-full rounded-md border border-line px-3 py-2"></div>
        <div><label for="type" class="block text-sm font-medium">{{ __('catalog::admin.products.type') }}</label>
            <select id="type" name="type" class="block w-full rounded-md border border-line px-3 py-2">
                <option value="">{{ __('catalog::admin.all') }}</option>
                @foreach (['physical', 'digital', 'service'] as $t)<option value="{{ $t }}" @selected(($filters['type'] ?? '') === $t)>{{ __('catalog::admin.products.types.'.$t) }}</option>@endforeach
            </select></div>
        <div><label for="status" class="block text-sm font-medium">{{ __('catalog::admin.status') }}</label>
            <select id="status" name="status" class="block w-full rounded-md border border-line px-3 py-2">
                <option value="">{{ __('catalog::admin.all') }}</option>
                @foreach (['draft', 'published', 'archived'] as $s)<option value="{{ $s }}" @selected(($filters['status'] ?? '') === $s)>{{ __('catalog::admin.products.statuses.'.$s) }}</option>@endforeach
            </select></div>
        <div><label for="category" class="block text-sm font-medium">{{ __('catalog::admin.menu.categories') }}</label>
            <select id="category" name="category" class="block w-full rounded-md border border-line px-3 py-2">
                <option value="">{{ __('catalog::admin.all') }}</option>
                @foreach ($categories as $c)<option value="{{ $c->public_id }}" @selected(($filters['category'] ?? '') === $c->public_id)>{{ $c->translate('name') }}</option>@endforeach
            </select></div>
        <div><label for="brand" class="block text-sm font-medium">{{ __('catalog::admin.menu.brands') }}</label>
            <select id="brand" name="brand" class="block w-full rounded-md border border-line px-3 py-2">
                <option value="">{{ __('catalog::admin.all') }}</option>
                @foreach ($brands as $b)<option value="{{ $b->public_id }}" @selected(($filters['brand'] ?? '') === $b->public_id)>{{ $b->translate('name') }}</option>@endforeach
            </select></div>
        <div class="sm:col-span-2 lg:col-span-6"><button class="min-h-11 rounded-md border border-line px-5 py-2">{{ __('catalog::admin.filter') }}</button></div>
    </form>

    <table class="w-full text-sm">
        <caption class="sr-only">{{ __('catalog::admin.products.title') }}</caption>
        <thead><tr class="border-b border-line">
            <th scope="col" class="py-2 text-start">{{ __('catalog::admin.fields.name') }}</th>
            <th scope="col" class="py-2 text-start">{{ __('catalog::admin.products.type') }}</th>
            <th scope="col" class="py-2 text-start">{{ __('catalog::admin.menu.categories') }}</th>
            <th scope="col" class="py-2 text-start">{{ __('catalog::admin.products.variants') }}</th>
            <th scope="col" class="py-2 text-start">{{ __('catalog::admin.languages') }}</th>
            <th scope="col" class="py-2 text-start">{{ __('catalog::admin.status') }}</th>
        </tr></thead>
        <tbody>
        @forelse ($products as $product)
            <tr class="border-b border-line">
                <td class="py-2"><a class="underline" href="{{ route('admin.catalog.products.edit', $product) }}">{{ $product->translate('name') ?? '—' }}</a></td>
                <td class="py-2">{{ __('catalog::admin.products.types.'.$product->product_type->value) }}</td>
                <td class="py-2">{{ $product->categories->map(fn ($c) => $c->translate('name'))->join(', ') }}</td>
                <td class="py-2">{{ \App\Modules\Platform\Support\Fmt::number($product->variants_count) }}</td>
                <td class="py-2">{{ $product->translations->pluck('locale')->map(fn ($l) => strtoupper($l))->join(', ') }}</td>
                <td class="py-2">{{ __('catalog::admin.products.statuses.'.$product->status->value) }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="py-6 text-center text-muted">{{ __('catalog::admin.empty') }}</td></tr>
        @endforelse
        </tbody>
    </table>
    {{ $products->links() }}
</div>
@endsection
