@extends('layouts.admin')

@section('title', $variant->sku)

@section('content')
<div class="max-w-3xl space-y-8">
    <div>
        <h1 class="text-2xl font-bold">{{ $variant->sku }}</h1>
        <p class="text-muted">{{ $variant->productName }}</p>
    </div>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    <x-platform::form.errors />

    <form method="POST" action="{{ route('admin.pricing.update', $variant->sku) }}" class="space-y-4" novalidate>
        @csrf @method('PUT')
        <h2 class="text-lg font-semibold">{{ __('pricing::admin.regular') }}</h2>
        <x-platform::form.field name="amount" inputmode="decimal" :value="$price ? $price['amount']->toMajorString() : ''" :label="__('pricing::admin.amount')" :help="__('pricing::admin.amount_help')" required />
        <x-platform::form.field name="compare_at" inputmode="decimal" :value="$price && $price['compare_at'] ? $price['compare_at']->toMajorString() : ''" :label="__('pricing::admin.compare_at')" :help="__('pricing::admin.compare_at_help')" />
        @can('pricing.update')<x-platform::form.button>{{ __('pricing::admin.save') }}</x-platform::form.button>@endcan
    </form>

    <section aria-labelledby="sales-h" class="space-y-3">
        <h2 id="sales-h" class="text-lg font-semibold">{{ __('pricing::admin.sales') }}</h2>
        @forelse ($sales as $sale)
            <div class="flex items-center justify-between gap-3 rounded-md border border-line p-3">
                <span>{{ \App\Modules\Platform\Support\Fmt::money($sale['amount']) }} · {{ \App\Modules\Platform\Support\Fmt::dateTime(\Carbon\CarbonImmutable::parse($sale['starts_at'], 'UTC')) }} → {{ \App\Modules\Platform\Support\Fmt::dateTime(\Carbon\CarbonImmutable::parse($sale['ends_at'], 'UTC')) }}</span>
                @can('pricing.update')
                    <form method="POST" action="{{ route('admin.pricing.sales.destroy', [$variant->sku, $sale['id']]) }}">@csrf @method('DELETE')<button class="underline">{{ __('pricing::admin.cancel_sale') }}</button></form>
                @endcan
            </div>
        @empty
            <p class="text-muted">{{ __('pricing::admin.no_sales') }}</p>
        @endforelse

        @can('pricing.update')
            <form method="POST" action="{{ route('admin.pricing.sales.store', $variant->sku) }}" class="space-y-3 rounded-md border border-line p-4" novalidate>
                @csrf
                <h3 class="font-semibold">{{ __('pricing::admin.schedule_sale') }}</h3>
                <x-platform::form.field name="sale_amount" inputmode="decimal" :label="__('pricing::admin.sale_amount')" />
                <x-platform::form.field name="starts_at" type="datetime-local" :label="__('pricing::admin.starts_at')" :help="__('pricing::admin.dhaka_time')" />
                <x-platform::form.field name="ends_at" type="datetime-local" :label="__('pricing::admin.ends_at')" :help="__('pricing::admin.ends_help')" />
                <x-platform::form.button>{{ __('pricing::admin.schedule_sale') }}</x-platform::form.button>
            </form>
        @endcan
    </section>
</div>
@endsection
