@extends('layouts.storefront')

@section('title', __('platform::ui.dev.title'))

@php
    $price = \App\Modules\Platform\DTOs\Money::of(185000);
    $was = \App\Modules\Platform\DTOs\Money::of(220000);
@endphp

@section('content')
<div class="space-y-10">
    <header>
        <h1 class="text-3xl font-bold">{{ __('platform::ui.dev.title') }}</h1>
        <p class="mt-1 text-muted">{{ __('platform::ui.dev.intro') }}</p>
    </header>

    <section aria-labelledby="c-buttons" class="space-y-3">
        <h2 id="c-buttons" class="text-xl font-semibold">{{ __('platform::ui.dev.h_buttons') }}</h2>
        <div class="flex flex-wrap gap-3">
            <x-platform::btn>{{ __('platform::ui.search') }}</x-platform::btn>
            <x-platform::btn variant="secondary">{{ __('platform::ui.menu') }}</x-platform::btn>
            <x-platform::btn variant="link" href="#">{{ __('platform::ui.next') }}</x-platform::btn>
            <x-platform::btn variant="danger">{{ __('platform::ui.close') }}</x-platform::btn>
            <x-platform::btn size="sm">{{ __('platform::ui.search') }}</x-platform::btn>
        </div>
    </section>

    <section aria-labelledby="c-badges" class="space-y-3">
        <h2 id="c-badges" class="text-xl font-semibold">{{ __('platform::ui.dev.h_badges') }}</h2>
        <div class="flex flex-wrap items-center gap-3">
            <x-platform::badge>{{ __('platform::ui.new') }}</x-platform::badge>
            <x-platform::badge tone="brand">{{ __('platform::ui.sale') }}</x-platform::badge>
            <x-platform::badge tone="success">{{ __('platform::ui.in_stock') }}</x-platform::badge>
            <x-platform::badge tone="warning">{{ __('platform::ui.low_stock') }}</x-platform::badge>
            <x-platform::badge tone="danger">{{ __('platform::ui.out_of_stock') }}</x-platform::badge>
            <x-platform::price :amount="$price" :compare-at="$was" size="lg" />
            <x-platform::rating :value="4.4" :count="128" />
        </div>
    </section>

    <section aria-labelledby="c-cards" class="space-y-3">
        <h2 id="c-cards" class="text-xl font-semibold">{{ __('platform::ui.dev.h_cards') }}</h2>
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            <x-platform::product-card :name="__('platform::ui.dev.sample_name')" url="#" :price="$price" :compare-at="$was" :badge="__('platform::ui.sale')" :status="__('platform::ui.in_stock')" />
            <x-platform::service-card :name="__('platform::ui.dev.sample_service')" url="#" :summary="__('platform::ui.dev.sample_summary')" :price="$price" />
        </div>
    </section>

    <section aria-labelledby="c-forms" class="max-w-md space-y-4">
        <h2 id="c-forms" class="text-xl font-semibold">{{ __('platform::ui.dev.h_forms') }}</h2>
        <x-platform::form.field name="demo_text" :label="__('platform::ui.search')" />
        <x-platform::form.select name="demo_select" :label="__('platform::ui.menu')" :options="['a' => __('platform::ui.new'), 'b' => __('platform::ui.sale')]" />
        <x-platform::form.checkbox name="demo_check" :label="__('platform::ui.in_stock')" />
        <p class="text-sm text-danger" role="alert">{{ __('platform::ui.dev.sample_error') }}</p>
    </section>

    <section aria-labelledby="c-nav" class="space-y-4">
        <h2 id="c-nav" class="text-xl font-semibold">{{ __('platform::ui.dev.h_nav') }}</h2>
        <x-platform::breadcrumb :items="[['label' => __('platform::ui.menu'), 'url' => '#'], ['label' => __('platform::ui.search'), 'url' => '#'], ['label' => __('platform::ui.dev.sample_name')]]" />
        {{ (new \Illuminate\Pagination\LengthAwarePaginator([], 120, 12, 3, ['path' => '#']))->links() }}
    </section>

    <section aria-labelledby="c-feedback" class="space-y-4">
        <h2 id="c-feedback" class="text-xl font-semibold">{{ __('platform::ui.dev.h_feedback') }}</h2>
        <x-platform::alert type="success">{{ __('platform::ui.dev.sample_toast') }}</x-platform::alert>
        <x-platform::toast type="success">{{ __('platform::ui.dev.sample_toast') }}</x-platform::toast>
        <x-platform::empty-state :title="__('platform::ui.dev.empty_title')" :text="__('platform::ui.dev.empty_text')" />
        <div class="max-w-sm space-y-2"><x-platform::skeleton class="h-40 w-full" /><x-platform::skeleton class="h-4 w-3/4" /><x-platform::skeleton class="h-4 w-1/2" /></div>
        <x-platform::btn @click="$dispatch('open-modal', 'demo')">{{ __('platform::ui.dev.open_modal') }}</x-platform::btn>
        <x-platform::modal id="demo" :title="__('platform::ui.dev.modal_title')"><p>{{ __('platform::ui.dev.modal_text') }}</p></x-platform::modal>
    </section>
</div>
@endsection
