@extends('layouts.admin')

@section('title', __('pricing::promotions.coupons.title'))

@php
    $fmt = fn (int $minor) => \App\Modules\Platform\Support\Fmt::money(\App\Modules\Platform\DTOs\Money::of($minor));
    $state = function ($c) use ($now): string {
        return match (true) {
            ! $c->is_active => 'off',
            $c->starts_at !== null && $c->starts_at > $now => 'scheduled',
            $c->ends_at !== null && $c->ends_at <= $now => 'ended',
            $c->usage_limit !== null && $c->used_count >= $c->usage_limit => 'used_up',
            default => 'live',
        };
    };
    $value = fn ($c) => match ($c->type) {
        'percent' => \App\Modules\Platform\Support\Fmt::number(rtrim(rtrim(number_format($c->value / 100, 2, '.', ''), '0'), '.')).'%',
        'fixed' => $fmt((int) $c->value),
        default => __('pricing::promotions.coupons.types.free_shipping'),
    };
@endphp

@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between gap-4">
        <h1 class="text-2xl font-bold">{{ __('pricing::promotions.coupons.title') }}</h1>
        @can('promotions.manage')<a href="{{ route('admin.promotions.coupons.create') }}" class="inline-flex min-h-11 items-center rounded-md bg-brand-600 px-5 py-2 font-medium text-white hover:bg-brand-700">{{ __('pricing::promotions.coupons.add') }}</a>@endcan
    </div>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif

    <form method="GET" role="search" class="flex items-end gap-3">
        <div class="flex-1"><label for="q" class="block text-sm font-medium">{{ __('pricing::promotions.coupons.search') }}</label>
            <input id="q" name="q" value="{{ $q }}" maxlength="64" class="block w-full rounded-md border border-line px-3 py-2"></div>
        <button class="min-h-11 rounded-md border border-line px-5 py-2">{{ __('pricing::promotions.filter') }}</button>
    </form>

    <div class="overflow-x-auto">
    <table class="w-full text-sm">
        <caption class="sr-only">{{ __('pricing::promotions.coupons.title') }}</caption>
        <thead><tr class="border-b border-line">
            @foreach (['code', 'discount', 'used', 'given', 'status'] as $col)<th scope="col" class="py-2 pe-3 text-start">{{ __('pricing::promotions.coupons.cols.'.$col) }}</th>@endforeach
        </tr></thead>
        <tbody>
        @forelse ($coupons as $c)
            <tr class="border-b border-line">
                <td class="py-2 pe-3"><a class="font-medium underline" href="{{ route('admin.promotions.coupons.show', $c->public_id) }}">{{ $c->code }}</a>@if ($c->description)<br><span class="text-muted">{{ $c->description }}</span>@endif</td>
                <td class="py-2 pe-3">{{ $value($c) }}</td>
                <td class="py-2 pe-3">{{ \App\Modules\Platform\Support\Fmt::number($c->used_count) }}{{ $c->usage_limit !== null ? ' / '.\App\Modules\Platform\Support\Fmt::number($c->usage_limit) : '' }}</td>
                <td class="py-2 pe-3">{{ $fmt((int) $c->given) }}</td>
                <td class="py-2 pe-3">{{ __('pricing::promotions.coupons.states.'.$state($c)) }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="py-6 text-center text-muted">{{ __('pricing::promotions.coupons.empty') }}</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
    {{ $coupons->links() }}
</div>
@endsection
