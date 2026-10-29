@extends('layouts.admin')

@section('title', $coupon ? __('pricing::promotions.coupons.edit_title', ['code' => $coupon->code]) : __('pricing::promotions.coupons.new_title'))

@php $canManage = auth('admin')->user()?->can('promotions.manage') === true; @endphp

@section('content')
<div class="max-w-2xl space-y-6" x-data="{ type: '{{ old('type', $coupon->type ?? 'percent') }}', scope: '{{ old('scope', $coupon->scope ?? 'all') }}' }">
    <a class="text-sm underline" href="{{ $coupon ? route('admin.promotions.coupons.show', $coupon->public_id) : route('admin.promotions.coupons.index') }}">← {{ __('pricing::promotions.back') }}</a>
    <h1 class="text-2xl font-bold">{{ $coupon ? __('pricing::promotions.coupons.edit_title', ['code' => $coupon->code]) : __('pricing::promotions.coupons.new_title') }}</h1>
    @error('coupon')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror

    <form method="POST" action="{{ $coupon ? route('admin.promotions.coupons.update', $coupon->public_id) : route('admin.promotions.coupons.store') }}" class="space-y-6" novalidate>
        @csrf
        @if ($coupon) @method('PUT') @endif

        @if ($coupon)
            <p>{{ __('pricing::promotions.coupons.cols.code') }}: <strong>{{ $coupon->code }}</strong> <span class="text-muted">({{ __('pricing::promotions.coupons.code_fixed') }})</span></p>
        @else
            <x-platform::form.field name="code" autocapitalize="characters" :label="__('pricing::promotions.coupons.cols.code')" :help="__('pricing::promotions.coupons.code_help')" required />
        @endif
        <x-platform::form.field name="description" :value="$coupon->description ?? ''" :label="__('pricing::promotions.coupons.description')" :help="__('pricing::promotions.coupons.description_help')" />

        <fieldset class="space-y-3">
            <legend class="text-lg font-semibold">{{ __('pricing::promotions.coupons.discount') }}</legend>
            <x-platform::form.select name="type" x-model="type" :label="__('pricing::promotions.coupons.type')" :value="$coupon->type ?? 'percent'" :options="['percent' => __('pricing::promotions.coupons.types.percent'), 'fixed' => __('pricing::promotions.coupons.types.fixed'), 'free_shipping' => __('pricing::promotions.coupons.types.free_shipping')]" />
            <div x-show="type !== 'free_shipping'">
                <x-platform::form.field name="value" inputmode="decimal" :value="$valueText" :label="__('pricing::promotions.coupons.value')" :help="__('pricing::promotions.coupons.value_help')" />
            </div>
            <div x-show="type === 'percent'">
                <x-platform::form.field name="max_discount" inputmode="decimal" :value="$maxText" :label="__('pricing::promotions.coupons.max_discount')" :help="__('pricing::promotions.coupons.max_discount_help')" />
            </div>
            <x-platform::form.field name="min_spend" inputmode="decimal" :value="$minText" :label="__('pricing::promotions.coupons.min_spend')" :help="__('pricing::promotions.coupons.min_spend_help')" />
        </fieldset>

        <fieldset class="space-y-3">
            <legend class="text-lg font-semibold">{{ __('pricing::promotions.coupons.applies_to') }}</legend>
            <x-platform::form.select name="scope" x-model="scope" :label="__('pricing::promotions.coupons.scope')" :value="$coupon->scope ?? 'all'" :options="['all' => __('pricing::promotions.coupons.scopes.all'), 'products' => __('pricing::promotions.coupons.scopes.products'), 'categories' => __('pricing::promotions.coupons.scopes.categories')]" />
            <div x-show="scope === 'products'" class="space-y-1">
                <label for="f-skus" class="block text-sm font-medium">{{ __('pricing::promotions.coupons.skus') }}</label>
                <textarea id="f-skus" name="skus" rows="4" class="block w-full rounded-md border border-line px-3 py-2 font-mono">{{ old('skus', $skuText) }}</textarea>
                <p class="text-sm text-muted">{{ __('pricing::promotions.coupons.skus_help') }}</p>
                @error('skus')<p class="text-sm text-danger">{{ $message }}</p>@enderror
            </div>
            <fieldset x-show="scope === 'categories'" class="space-y-1">
                <legend class="text-sm font-medium">{{ __('pricing::promotions.coupons.categories') }}</legend>
                <p class="text-sm text-muted">{{ __('pricing::promotions.coupons.categories_help') }}</p>
                @error('categories')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                @php $picked = collect(old('categories', $chosenCategories))->map(fn ($i) => (int) $i)->all(); @endphp
                @foreach ($categoryOptions as $cat)
                    <label class="flex min-h-9 items-center gap-2" style="padding-inline-start: {{ $cat['depth'] * 1.25 }}rem">
                        <input type="checkbox" name="categories[]" value="{{ $cat['id'] }}" class="size-5 rounded border-line" @checked(in_array($cat['id'], $picked, true))> {{ $cat['name'] }}
                    </label>
                @endforeach
            </fieldset>
            @unless ($canManage)<noscript></noscript>@endunless
        </fieldset>

        <fieldset class="space-y-3">
            <legend class="text-lg font-semibold">{{ __('pricing::promotions.coupons.when') }}</legend>
            <p class="text-sm text-muted">{{ __('pricing::promotions.dhaka_time') }}</p>
            <x-platform::form.field name="starts_at" type="datetime-local" :value="$startsText" :label="__('pricing::promotions.starts')" />
            <x-platform::form.field name="ends_at" type="datetime-local" :value="$endsText" :label="__('pricing::promotions.ends')" :help="__('pricing::promotions.ends_help')" />
            <x-platform::form.field name="usage_limit" inputmode="numeric" :value="$coupon->usage_limit ?? ''" :label="__('pricing::promotions.coupons.usage_limit')" :help="__('pricing::promotions.coupons.usage_limit_help')" />
            <x-platform::form.field name="per_customer_limit" inputmode="numeric" :value="$coupon->per_customer_limit ?? ''" :label="__('pricing::promotions.coupons.per_customer')" :help="__('pricing::promotions.coupons.per_customer_help')" />
            <x-platform::form.checkbox name="is_active" :label="__('pricing::promotions.active')" :checked="$coupon->is_active ?? true" />
        </fieldset>

        @if ($canManage)<x-platform::form.button>{{ __('pricing::promotions.save') }}</x-platform::form.button>@endif
    </form>
</div>
@endsection
