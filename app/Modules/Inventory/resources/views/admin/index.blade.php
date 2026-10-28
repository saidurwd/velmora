@extends('layouts.admin')

@section('title', __('inventory::admin.title'))

@section('content')
<div class="space-y-6">
    <h1 class="text-2xl font-bold">{{ __('inventory::admin.title') }}</h1>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif

    <form method="GET" role="search" class="flex flex-wrap items-end gap-3">
        <div><label for="q" class="block text-sm font-medium">{{ __('inventory::admin.search') }}</label>
            <input id="q" name="q" value="{{ $term }}" class="block rounded-md border border-line px-3 py-2"></div>
        <button class="min-h-11 rounded-md border border-line px-5 py-2">{{ __('inventory::admin.find') }}</button>
    </form>

    @if ($term !== '')
        <table class="w-full text-sm">
            <caption class="sr-only">{{ __('inventory::admin.title') }}</caption>
            <thead><tr class="border-b border-line">
                <th scope="col" class="py-2 text-start">{{ __('inventory::admin.sku') }}</th>
                <th scope="col" class="py-2 text-start">{{ __('inventory::admin.product') }}</th>
                <th scope="col" class="py-2 text-end">{{ __('inventory::admin.on_hand') }}</th>
                <th scope="col" class="py-2 text-end">{{ __('inventory::admin.reserved') }}</th>
                <th scope="col" class="py-2 text-end">{{ __('inventory::admin.available') }}</th>
            </tr></thead>
            <tbody>
            @forelse ($variants as $v)
                <tr class="border-b border-line">
                    <td class="py-2"><a class="underline" href="{{ route('admin.inventory.show', $v->sku) }}">{{ $v->sku }}</a></td>
                    <td class="py-2">{{ $v->productName }}</td>
                    @if (! $v->trackInventory)
                        <td colspan="3" class="py-2 text-end text-muted">{{ __('inventory::admin.not_tracked') }}</td>
                    @else
                        <td class="py-2 text-end">{{ \App\Modules\Platform\Support\Fmt::number($totals[$v->id]['on_hand'] ?? 0) }}</td>
                        <td class="py-2 text-end">{{ \App\Modules\Platform\Support\Fmt::number($totals[$v->id]['reserved'] ?? 0) }}</td>
                        <td class="py-2 text-end">{{ \App\Modules\Platform\Support\Fmt::number($totals[$v->id]['available'] ?? 0) }}</td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="5" class="py-6 text-center text-muted">{{ __('inventory::admin.none_found') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    @endif
</div>
@endsection
