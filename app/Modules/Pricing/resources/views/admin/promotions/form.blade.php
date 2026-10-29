@extends('layouts.admin')

@section('title', $promotion ? __('pricing::promotions.promotions.edit_title') : __('pricing::promotions.promotions.new_title'))

@php $canManage = auth('admin')->user()?->can('promotions.manage') === true; @endphp

@section('content')
<div class="max-w-2xl space-y-6" x-data="{ type: '{{ old('type', $promotion->type ?? 'percent') }}' }">
    <a class="text-sm underline" href="{{ route('admin.promotions.promotions.index') }}">← {{ __('pricing::promotions.back') }}</a>
    <h1 class="text-2xl font-bold">{{ $promotion ? __('pricing::promotions.promotions.edit_title') : __('pricing::promotions.promotions.new_title') }}</h1>
    @error('promotion')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror

    <form method="POST" action="{{ $promotion ? route('admin.promotions.promotions.update', $promotion->public_id) : route('admin.promotions.promotions.store') }}" class="space-y-6" novalidate>
        @csrf
        @if ($promotion) @method('PUT') @endif

        <x-platform::translation-fields :translations="$translations" required="label" :fields="['label' => ['label' => __('pricing::promotions.promotions.label'), 'type' => 'text']]" />
        <p class="-mt-3 text-sm text-muted">{{ __('pricing::promotions.promotions.label_help') }}</p>

        <x-platform::form.select name="type" x-model="type" :label="__('pricing::promotions.coupons.type')" :value="$promotion->type ?? 'percent'" :options="['percent' => __('pricing::promotions.coupons.types.percent'), 'fixed' => __('pricing::promotions.coupons.types.fixed')]" />
        <x-platform::form.field name="value" inputmode="decimal" :value="$valueText" :label="__('pricing::promotions.coupons.value')" :help="__('pricing::promotions.coupons.value_help')" required />
        <div x-show="type === 'percent'"><x-platform::form.field name="max_discount" inputmode="decimal" :value="$maxText" :label="__('pricing::promotions.coupons.max_discount')" :help="__('pricing::promotions.coupons.max_discount_help')" /></div>
        <x-platform::form.field name="min_spend" inputmode="decimal" :value="$minText" :label="__('pricing::promotions.promotions.min_spend')" :help="__('pricing::promotions.promotions.min_spend_help')" />

        <p class="text-sm text-muted">{{ __('pricing::promotions.dhaka_time') }}</p>
        <x-platform::form.field name="starts_at" type="datetime-local" :value="$startsText" :label="__('pricing::promotions.starts')" />
        <x-platform::form.field name="ends_at" type="datetime-local" :value="$endsText" :label="__('pricing::promotions.ends')" :help="__('pricing::promotions.ends_help')" />
        <x-platform::form.checkbox name="is_active" :label="__('pricing::promotions.active')" :checked="$promotion->is_active ?? true" />

        @if ($canManage)<x-platform::form.button>{{ __('pricing::promotions.save') }}</x-platform::form.button>@endif
    </form>
</div>
@endsection
