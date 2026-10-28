@extends('layouts.admin')

@section('title', $variant->sku)

@section('content')
<div class="max-w-4xl space-y-8">
    <div>
        <h1 class="text-2xl font-bold">{{ $variant->sku }}</h1>
        <p class="text-muted">{{ $variant->productName }}</p>
    </div>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    <x-platform::form.errors />

    @if (! $variant->trackInventory)
        <x-platform::alert type="info">{{ __('inventory::admin.not_tracked') }}</x-platform::alert>
    @else
        <table class="w-full text-sm">
            <caption class="sr-only">{{ __('inventory::admin.levels') }}</caption>
            <thead><tr class="border-b border-line">
                <th scope="col" class="py-2 text-start">{{ __('inventory::admin.location') }}</th>
                <th scope="col" class="py-2 text-end">{{ __('inventory::admin.on_hand') }}</th>
                <th scope="col" class="py-2 text-end">{{ __('inventory::admin.reserved') }}</th>
                <th scope="col" class="py-2 text-end">{{ __('inventory::admin.available') }}</th>
            </tr></thead>
            <tbody>
            @foreach ($locations as $l)
                <tr class="border-b border-line">
                    <td class="py-2">{{ $l['name'] }}</td>
                    <td class="py-2 text-end">{{ \App\Modules\Platform\Support\Fmt::number($l['on_hand']) }}</td>
                    <td class="py-2 text-end">{{ \App\Modules\Platform\Support\Fmt::number($l['reserved']) }}</td>
                    <td class="py-2 text-end">{{ \App\Modules\Platform\Support\Fmt::number($l['available']) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>

        @can('inventory.adjust')
            <form method="POST" action="{{ route('admin.inventory.adjust', $variant->sku) }}" class="space-y-3 rounded-md border border-line p-4" novalidate>
                @csrf
                <h2 class="text-lg font-semibold">{{ __('inventory::admin.adjust') }}</h2>
                <div class="space-y-1">
                    <label for="location" class="block text-sm font-medium">{{ __('inventory::admin.location') }}</label>
                    <select id="location" name="location" class="block rounded-md border border-line px-3 py-2">
                        @foreach ($locations as $l)<option value="{{ $l['code'] }}" @selected(old('location') === $l['code'])>{{ $l['name'] }}</option>@endforeach
                    </select>
                </div>
                <div class="space-y-1">
                    <label for="reason" class="block text-sm font-medium">{{ __('inventory::admin.reason') }}</label>
                    <select id="reason" name="reason" class="block rounded-md border border-line px-3 py-2">
                        @foreach ($reasons as $r)<option value="{{ $r }}" @selected(old('reason') === $r)>{{ __('inventory::admin.reasons.'.$r) }}</option>@endforeach
                    </select>
                </div>
                <x-platform::form.field name="quantity" inputmode="numeric" :label="__('inventory::admin.quantity')" :help="__('inventory::admin.quantity_help')" />
                <x-platform::form.field name="note" :label="__('inventory::admin.note')" :help="__('inventory::admin.note_help')" />
                <x-platform::form.button>{{ __('inventory::admin.record') }}</x-platform::form.button>
            </form>
        @endcan
    @endif

    <section aria-labelledby="mv-h" class="space-y-3">
        <h2 id="mv-h" class="text-lg font-semibold">{{ __('inventory::admin.history') }}</h2>
        <table class="w-full text-sm">
            <caption class="sr-only">{{ __('inventory::admin.history') }}</caption>
            <thead><tr class="border-b border-line">
                <th scope="col" class="py-2 text-start">{{ __('inventory::admin.when') }}</th>
                <th scope="col" class="py-2 text-start">{{ __('inventory::admin.location') }}</th>
                <th scope="col" class="py-2 text-start">{{ __('inventory::admin.reason') }}</th>
                <th scope="col" class="py-2 text-end">{{ __('inventory::admin.change') }}</th>
                <th scope="col" class="py-2 text-start">{{ __('inventory::admin.note') }}</th>
            </tr></thead>
            <tbody>
            @forelse ($movements as $m)
                <tr class="border-b border-line">
                    <td class="py-2">{{ \App\Modules\Platform\Support\Fmt::dateTime(\Carbon\CarbonImmutable::parse($m->created_at, 'UTC')) }}</td>
                    <td class="py-2">{{ $m->location }}</td>
                    <td class="py-2">{{ __('inventory::admin.reasons.'.$m->reason) }}</td>
                    <td class="py-2 text-end">{{ $m->delta > 0 ? '+' : '' }}{{ \App\Modules\Platform\Support\Fmt::number($m->delta) }}</td>
                    <td class="py-2">{{ $m->note }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="py-6 text-center text-muted">{{ __('inventory::admin.no_history') }}</td></tr>
            @endforelse
            </tbody>
        </table>
        {{ $movements->links() }}
    </section>
</div>
@endsection
