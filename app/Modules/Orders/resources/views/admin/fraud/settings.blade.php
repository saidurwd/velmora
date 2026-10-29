@extends('layouts.admin')

@section('title', __('orders::fraud.settings_title'))

@php $canManage = auth('admin')->user()?->can('settings.manage') === true; @endphp

@section('content')
<div class="max-w-2xl space-y-6">
    <h1 class="text-2xl font-bold">{{ __('orders::fraud.settings_title') }}</h1>
    <p class="text-muted">{{ __('orders::fraud.settings_help') }}</p>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    @error('settings')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror

    <form method="POST" action="{{ route('admin.orders.fraud.settings.update') }}" class="space-y-6" novalidate>
        @csrf @method('PUT')
        <x-platform::form.checkbox name="enabled" :label="__('orders::fraud.enabled')" :checked="$v['fraud.enabled']" />
        <p class="-mt-4 text-sm text-muted">{{ __('orders::fraud.enabled_help') }}</p>
        <x-platform::form.field name="max_open_cod_per_phone" inputmode="numeric" :value="$v['fraud.max_open_cod_per_phone']" :label="__('orders::fraud.max_open')" :help="__('orders::fraud.max_open_help')" required />
        <x-platform::form.field name="high_value_cod" inputmode="decimal" :value="$highValue" :label="__('orders::fraud.high_value')" :help="__('orders::fraud.high_value_help')" required />
        <x-platform::form.field name="velocity_orders_per_day" inputmode="numeric" :value="$v['fraud.velocity_orders_per_day']" :label="__('orders::fraud.velocity')" :help="__('orders::fraud.velocity_help')" required />
        <x-platform::form.field name="address_max_phones" inputmode="numeric" :value="$v['fraud.address_max_phones']" :label="__('orders::fraud.address_phones')" :help="__('orders::fraud.address_phones_help')" required />
        @if ($canManage)<x-platform::form.button>{{ __('orders::fraud.save') }}</x-platform::form.button>@else<p class="text-sm text-muted">{{ __('orders::fraud.view_only') }}</p>@endif
    </form>
</div>
@endsection
