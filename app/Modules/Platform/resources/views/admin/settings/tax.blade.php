@extends('layouts.admin')

@section('title', __('platform::settings.tax.title'))

@section('content')
<div class="max-w-2xl space-y-6">
    <h1 class="text-2xl font-bold">{{ __('platform::settings.tax.title') }}</h1>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    <x-platform::alert type="info">{{ __('platform::settings.tax.warning') }}</x-platform::alert>
    <x-platform::form.errors />

    <form method="POST" action="{{ route('admin.settings.tax.update') }}" class="space-y-4" novalidate>
        @csrf @method('PUT')
        <input type="hidden" name="version" value="{{ $version }}">
        @foreach ($values as $name => $on)
            <label class="flex items-start gap-3 rounded-md border border-line p-3">
                <input type="checkbox" name="{{ $name }}" value="1" class="mt-1 size-4" @checked(old($name, $on))>
                <span><span class="block font-medium">{{ __('platform::settings.tax.fields.'.$name) }}</span><span class="block text-sm text-muted">{{ __('platform::settings.tax.help.'.$name) }}</span></span>
            </label>
        @endforeach
        <p class="text-sm text-muted">{{ __('platform::settings.tax.rates_note') }}</p>
        <x-platform::form.button>{{ __('platform::branding.save') }}</x-platform::form.button>
    </form>
</div>
@endsection
