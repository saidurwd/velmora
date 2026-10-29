@extends('layouts.admin')

@section('title', __('pricing::promotions.usage.title', ['code' => $coupon->code]))

@php
    $fmt = fn (int $minor) => \App\Modules\Platform\Support\Fmt::money(\App\Modules\Platform\DTOs\Money::of($minor));
    $dhaka = fn (string $utc) => \App\Modules\Platform\Support\Fmt::dateTime(\Carbon\CarbonImmutable::parse($utc, 'UTC'));
@endphp

@section('content')
<div class="space-y-6">
    <a class="text-sm underline" href="{{ route('admin.promotions.coupons.index') }}">← {{ __('pricing::promotions.back') }}</a>
    <div class="flex items-center justify-between gap-4">
        <h1 class="text-2xl font-bold">{{ __('pricing::promotions.usage.title', ['code' => $coupon->code]) }}</h1>
        <a href="{{ route('admin.promotions.coupons.edit', $coupon->public_id) }}" class="inline-flex min-h-11 items-center rounded-md border border-line px-5 py-2">{{ auth('admin')->user()?->can('promotions.manage') ? __('pricing::promotions.edit') : __('pricing::promotions.view') }}</a>
    </div>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    @if ($coupon->description)<p class="text-muted">{{ $coupon->description }}</p>@endif

    <dl class="grid gap-4 sm:grid-cols-3">
        <div class="rounded-lg border border-line p-4"><dt class="text-sm text-muted">{{ __('pricing::promotions.usage.uses') }}</dt><dd class="text-2xl font-semibold">{{ \App\Modules\Platform\Support\Fmt::number($uses) }}{{ $coupon->usage_limit !== null ? ' / '.\App\Modules\Platform\Support\Fmt::number($coupon->usage_limit) : '' }}</dd></div>
        <div class="rounded-lg border border-line p-4"><dt class="text-sm text-muted">{{ __('pricing::promotions.usage.given') }}</dt><dd class="text-2xl font-semibold">{{ $fmt($given) }}</dd></div>
        <div class="rounded-lg border border-line p-4"><dt class="text-sm text-muted">{{ __('pricing::promotions.usage.people') }}</dt><dd class="text-2xl font-semibold">{{ \App\Modules\Platform\Support\Fmt::number($people) }}</dd></div>
    </dl>

    <section aria-labelledby="orders-h" class="space-y-2">
        <h2 id="orders-h" class="text-lg font-semibold">{{ __('pricing::promotions.usage.orders') }}</h2>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <caption class="sr-only">{{ __('pricing::promotions.usage.orders') }}</caption>
            <thead><tr class="border-b border-line">
                @foreach (['when', 'order', 'amount', 'who'] as $col)<th scope="col" class="py-2 pe-3 text-start">{{ __('pricing::promotions.usage.cols.'.$col) }}</th>@endforeach
            </tr></thead>
            <tbody>
            @forelse ($redemptions as $r)
                @php $o = $orders[(int) $r->order_id] ?? null; @endphp
                <tr class="border-b border-line">
                    <td class="py-2 pe-3">{{ $dhaka($r->redeemed_at) }}</td>
                    <td class="py-2 pe-3">@if ($o && auth('admin')->user()?->can('orders.view'))<a class="underline" href="{{ route('admin.orders.show', $o['public_id']) }}">{{ $o['number'] }}</a>@else{{ $o['number'] ?? '—' }}@endif</td>
                    <td class="py-2 pe-3">{{ $fmt((int) $r->amount_minor) }}</td>
                    <td class="py-2 pe-3">{{ str_starts_with((string) $r->redeemer, 'u:') ? __('pricing::promotions.usage.account') : __('pricing::promotions.usage.guest') }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="py-6 text-center text-muted">{{ __('pricing::promotions.usage.none') }}</td></tr>
            @endforelse
            </tbody>
        </table>
        </div>
        {{ $redemptions->links() }}
    </section>
</div>
@endsection
