@extends('layouts.admin')

@section('title', $method ? __('shipping::admin.methods.edit_title') : __('shipping::admin.methods.new_title'))

@php
    $major = fn (?int $minor): string => $minor === null ? '' : \App\Modules\Platform\DTOs\Money::of($minor)->toMajorString();
    $canManage = auth('admin')->user()?->can('shipping.manage') === true;
@endphp

@section('content')
<div class="max-w-2xl space-y-6">
    <a class="text-sm underline" href="{{ route('admin.shipping.methods.index') }}">← {{ __('shipping::admin.back') }}</a>
    <h1 class="text-2xl font-bold">{{ $method ? __('shipping::admin.methods.edit_title') : __('shipping::admin.methods.new_title') }}</h1>

    <form method="POST" action="{{ $method ? route('admin.shipping.methods.update', $method) : route('admin.shipping.methods.store') }}" class="space-y-6" novalidate>
        @csrf
        @if ($method) @method('PUT') @endif

        @if ($method)
            <p><strong>{{ __('shipping::admin.methods.code') }}:</strong> {{ $method->code }}</p>
        @else
            <x-platform::form.field name="code" :label="__('shipping::admin.methods.code')" :help="__('shipping::admin.methods.code_help')" required />
        @endif

        <x-platform::translation-fields :translations="$method?->translations" required="name" :fields="[
            'name' => ['label' => __('shipping::admin.methods.name'), 'type' => 'text'],
            'delivery_estimate' => ['label' => __('shipping::admin.methods.estimate'), 'type' => 'text'],
        ]" />

        <div class="space-y-1">
            <x-platform::form.checkbox name="is_active" :label="__('shipping::admin.active')" :checked="$method?->is_active ?? true" />
            <x-platform::form.checkbox name="cod_available" :label="__('shipping::admin.methods.cod')" :checked="$method?->cod_available ?? true" />
        </div>
        <x-platform::form.field name="cod_fee" inputmode="decimal" :value="$major($method?->cod_fee_minor)" :label="__('shipping::admin.methods.cod_fee')" :help="__('shipping::admin.methods.cod_fee_help')" />
        <x-platform::form.field name="free_over" inputmode="decimal" :value="$major($method?->free_over_minor)" :label="__('shipping::admin.methods.free_over')" :help="__('shipping::admin.methods.free_over_help')" />
        <x-platform::form.field name="sort_order" inputmode="numeric" :value="$method?->sort_order ?? 0" :label="__('shipping::admin.methods.sort')" />

        @if ($canManage)<x-platform::form.button>{{ __('shipping::admin.save') }}</x-platform::form.button>@endif
    </form>
</div>
@endsection
