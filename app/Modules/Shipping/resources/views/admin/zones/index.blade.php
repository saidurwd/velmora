@extends('layouts.admin')

@section('title', __('shipping::admin.zones.title'))

@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between gap-4">
        <h1 class="text-2xl font-bold">{{ __('shipping::admin.zones.title') }}</h1>
        @can('shipping.manage')<a href="{{ route('admin.shipping.zones.create') }}" class="inline-flex min-h-11 items-center rounded-md bg-brand-600 px-5 py-2 font-medium text-white hover:bg-brand-700">{{ __('shipping::admin.zones.add') }}</a>@endcan
    </div>
    <p class="text-muted">{{ __('shipping::admin.zones.help') }}</p>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    @unless ($hasFallback)<x-platform::alert type="error">{{ __('shipping::admin.zones.no_fallback') }}</x-platform::alert>@endunless

    <div class="overflow-x-auto">
    <table class="w-full text-sm">
        <caption class="sr-only">{{ __('shipping::admin.zones.title') }}</caption>
        <thead><tr class="border-b border-line">
            @foreach (['name', 'districts', 'cod', 'status'] as $c)<th scope="col" class="py-2 pe-3 text-start">{{ __('shipping::admin.zones.cols.'.$c) }}</th>@endforeach
        </tr></thead>
        <tbody>
        @foreach ($zones as $zone)
            <tr class="border-b border-line">
                <td class="py-2 pe-3"><a class="underline" href="{{ route('admin.shipping.zones.edit', $zone) }}">{{ $zone->translate('name') ?? '—' }}</a> @if ($zone->is_fallback)<x-platform::badge tone="brand">{{ __('shipping::admin.zones.fallback_badge') }}</x-platform::badge>@endif</td>
                <td class="py-2 pe-3">{{ \App\Modules\Platform\Support\Fmt::number((int) ($districtCounts[$zone->id] ?? 0)) }}</td>
                <td class="py-2 pe-3">{{ $zone->cod_available ? __('shipping::admin.yes') : __('shipping::admin.no') }}</td>
                <td class="py-2 pe-3">{{ $zone->is_active ? __('shipping::admin.active') : __('shipping::admin.inactive') }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    </div>
</div>
@endsection
