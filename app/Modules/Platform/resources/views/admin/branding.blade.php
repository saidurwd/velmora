@extends('layouts.admin')

@section('title', __('platform::branding.title'))

@section('content')
<div class="max-w-2xl space-y-6">
    <h1 class="text-2xl font-bold">{{ __('platform::branding.title') }}</h1>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    <x-platform::form.errors />

    <form method="POST" action="{{ route('admin.settings.branding.update') }}" enctype="multipart/form-data" class="space-y-6" novalidate>
        @csrf @method('PUT')

        <x-platform::form.field name="name" :value="$branding->name()" :label="__('platform::branding.name')" required />

        @foreach ($locales as $locale)
            <x-platform::form.field :name="'tagline['.$locale.']'" :value="old('tagline.'.$locale, $branding->tagline($locale))" :label="__('platform::branding.tagline').' ('.strtoupper($locale).')'" lang="{{ $locale }}" />
        @endforeach

        <div class="space-y-1">
            <label for="color" class="block text-sm font-medium">{{ __('platform::branding.color') }}</label>
            <p id="color-help" class="text-sm text-muted">{{ __('platform::branding.color_help') }}</p>
            <input id="color" name="color" type="color" value="{{ old('color', $branding->color()) }}" aria-describedby="color-help" class="h-11 w-24 rounded-md border border-line">
        </div>

        <div class="space-y-2">
            <label for="logo" class="block text-sm font-medium">{{ __('platform::branding.logo') }}</label>
            @if ($branding->logoUrl())
                <img src="{{ $branding->logoUrl() }}" alt="{{ $branding->name() }}" class="max-h-16 rounded border border-line p-2">
                <label class="flex items-center gap-2"><input type="checkbox" name="remove_logo" value="1" class="size-4"> {{ __('platform::branding.remove_logo') }}</label>
            @endif
            <input id="logo" name="logo" type="file" accept="image/png,image/jpeg,image/webp" class="block w-full">
            <p class="text-sm text-muted">{{ __('platform::branding.logo_help') }}</p>
        </div>

        <x-platform::form.button>{{ __('platform::branding.save') }}</x-platform::form.button>
    </form>
</div>
@endsection
