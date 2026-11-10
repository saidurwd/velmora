@extends('layouts.admin')

@section('title', __('platform::settings.business.title'))

@section('content')
<div class="max-w-3xl space-y-6">
    <h1 class="text-2xl font-bold">{{ __('platform::settings.business.title') }}</h1>
    <p class="text-muted">{{ __('platform::settings.business.intro') }}</p>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    <x-platform::form.errors />

    <form method="POST" action="{{ route('admin.settings.business.update') }}" class="space-y-6" novalidate>
        @csrf @method('PUT')
        <input type="hidden" name="version" value="{{ $version }}">

        <x-platform::translation-fields group="texts" :translations="$texts" :fields="[
            'legal_name' => ['label' => __('platform::settings.business.legal_name')],
            'address' => ['label' => __('platform::settings.business.address'), 'type' => 'textarea', 'rows' => 3],
            'invoice_footer' => ['label' => __('platform::settings.business.invoice_footer'), 'type' => 'textarea', 'rows' => 3],
        ]" />

        <div class="grid gap-4 sm:grid-cols-2">
            <x-platform::form.field name="phone" :value="old('phone', $profile->phone())" :label="__('platform::settings.business.phone')" />
            <x-platform::form.field name="email" type="email" :value="old('email', $profile->email())" :label="__('platform::settings.business.email')" />
            <x-platform::form.field name="bin" :value="old('bin', $profile->bin())" :label="__('platform::settings.business.bin')" :help="__('platform::settings.business.bin_help')" />
            <x-platform::form.field name="tin" :value="old('tin', $profile->tin())" :label="__('platform::settings.business.tin')" />
        </div>
        <p class="text-sm text-muted">{{ __('platform::settings.business.shown_on') }}</p>

        <x-platform::form.button>{{ __('platform::branding.save') }}</x-platform::form.button>
    </form>
</div>
@endsection
