@extends('layouts.admin')

@section('title', __('orders::fraud.blocklist_title'))

@php
    $canBlock = auth('admin')->user()?->can('orders.cancel') === true;
    $state = fn ($e) => match (true) {
        ! $e->is_active => 'off',
        $e->expires_at !== null && $e->expires_at <= $now => 'expired',
        default => 'blocked',
    };
@endphp

@section('content')
<div class="space-y-6">
    <h1 class="text-2xl font-bold">{{ __('orders::fraud.blocklist_title') }}</h1>
    <p class="text-muted">{{ __('orders::fraud.blocklist_help') }}</p>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif

    @if ($canBlock)
    <form method="POST" action="{{ route('admin.orders.fraud.blocklist.add') }}" class="grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end" novalidate>
        @csrf
        <x-platform::form.field name="phone" type="tel" inputmode="tel" :label="__('orders::fraud.phone')" required />
        <x-platform::form.field name="reason" :label="__('orders::fraud.reason')" />
        <x-platform::form.button>{{ __('orders::fraud.add_phone') }}</x-platform::form.button>
    </form>
    @endif

    <div class="overflow-x-auto">
    <table class="w-full text-sm">
        <caption class="sr-only">{{ __('orders::fraud.blocklist_title') }}</caption>
        <thead><tr class="border-b border-line">
            @foreach (['kind', 'what', 'reason', 'added', 'status'] as $col)<th scope="col" class="py-2 pe-3 text-start">{{ __('orders::fraud.cols.'.$col) }}</th>@endforeach
            @if ($canBlock)<th scope="col" class="py-2 text-start"><span class="sr-only">{{ __('orders::fraud.cols.action') }}</span></th>@endif
        </tr></thead>
        <tbody>
        @forelse ($entries as $e)
            <tr class="border-b border-line">
                <td class="py-2 pe-3">{{ __('orders::fraud.kinds.'.$e->kind) }}</td>
                <td class="py-2 pe-3">{{ $e->hint }}</td>
                <td class="py-2 pe-3">{{ $e->reason ?? '—' }}</td>
                <td class="py-2 pe-3">{{ \App\Modules\Platform\Support\Fmt::date(\Carbon\CarbonImmutable::parse($e->created_at, 'UTC')) }}</td>
                <td class="py-2 pe-3">{{ __('orders::fraud.states.'.$state($e)) }}</td>
                @if ($canBlock)
                <td class="py-2">
                    <form method="POST" action="{{ route('admin.orders.fraud.blocklist.toggle', $e->id) }}">@csrf
                        <input type="hidden" name="active" value="{{ $e->is_active ? 0 : 1 }}">
                        <button class="inline-flex min-h-9 items-center px-2 underline" aria-label="{{ ($e->is_active ? __('orders::fraud.unblock') : __('orders::fraud.block_again')).': '.$e->hint }}">{{ $e->is_active ? __('orders::fraud.unblock') : __('orders::fraud.block_again') }}</button>
                    </form>
                </td>
                @endif
            </tr>
        @empty
            <tr><td colspan="6" class="py-6 text-center text-muted">{{ __('orders::fraud.empty') }}</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
    {{ $entries->links() }}
</div>
@endsection
