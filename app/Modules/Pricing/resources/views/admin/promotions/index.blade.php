@extends('layouts.admin')

@section('title', __('pricing::promotions.promotions.title'))

@php
    $fmt = fn (int $minor) => \App\Modules\Platform\Support\Fmt::money(\App\Modules\Platform\DTOs\Money::of($minor));
    $state = fn ($p) => match (true) {
        ! $p->is_active => 'off',
        $p->starts_at !== null && $p->starts_at > $now => 'scheduled',
        $p->ends_at !== null && $p->ends_at <= $now => 'ended',
        default => 'live',
    };
@endphp

@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between gap-4">
        <h1 class="text-2xl font-bold">{{ __('pricing::promotions.promotions.title') }}</h1>
        @can('promotions.manage')<a href="{{ route('admin.promotions.promotions.create') }}" class="inline-flex min-h-11 items-center rounded-md bg-brand-600 px-5 py-2 font-medium text-white hover:bg-brand-700">{{ __('pricing::promotions.promotions.add') }}</a>@endcan
    </div>
    <p class="text-muted">{{ __('pricing::promotions.promotions.help') }}</p>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    <div class="overflow-x-auto">
    <table class="w-full text-sm">
        <caption class="sr-only">{{ __('pricing::promotions.promotions.title') }}</caption>
        <thead><tr class="border-b border-line">
            @foreach (['label', 'discount', 'min_spend', 'status'] as $col)<th scope="col" class="py-2 pe-3 text-start">{{ __('pricing::promotions.promotions.cols.'.$col) }}</th>@endforeach
        </tr></thead>
        <tbody>
        @forelse ($promotions as $p)
            <tr class="border-b border-line">
                <td class="py-2 pe-3"><a class="underline" href="{{ route('admin.promotions.promotions.edit', $p->public_id) }}">{{ $p->label ?? '—' }}</a></td>
                <td class="py-2 pe-3">{{ $p->type === 'percent' ? \App\Modules\Platform\Support\Fmt::number(rtrim(rtrim(number_format($p->value / 100, 2, '.', ''), '0'), '.')).'%' : $fmt((int) $p->value) }}</td>
                <td class="py-2 pe-3">{{ (int) $p->min_spend_minor > 0 ? $fmt((int) $p->min_spend_minor) : '—' }}</td>
                <td class="py-2 pe-3">{{ __('pricing::promotions.coupons.states.'.$state($p)) }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="py-6 text-center text-muted">{{ __('pricing::promotions.promotions.empty') }}</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</div>
@endsection
