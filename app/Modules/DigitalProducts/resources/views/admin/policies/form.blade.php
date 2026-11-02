@extends('layouts.admin')

@section('title', $policy ? __('digital-products::admin.policies.edit') : __('digital-products::admin.policies.new'))

@php
    $can = auth('admin')->user()?->can('digital.manage') === true;
    $rule = old('rule', $policy?->rule ?? 'until_first_use');
@endphp

@section('content')
<div class="max-w-3xl space-y-6">
    <div><a class="text-sm underline" href="{{ route('admin.digital.policies.index') }}">← {{ __('digital-products::admin.back') }}</a>
        <h1 class="mt-1 text-2xl font-bold">{{ $policy ? __('digital-products::admin.policies.edit') : __('digital-products::admin.policies.new') }}</h1></div>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    @error('policy')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror
    <form method="POST" action="{{ $policy ? route('admin.digital.policies.update', $policy->public_id) : route('admin.digital.policies.store') }}" class="space-y-5">
        @csrf
        @if ($policy) @method('PUT') @endif
        <div><label for="rule" class="block text-sm font-medium">{{ __('digital-products::admin.policies.rule') }}</label>
            <select id="rule" name="rule" @disabled(! $can) class="mt-1 block w-full rounded-md border border-line px-3 py-2">@foreach (\App\Modules\DigitalProducts\Models\RefundPolicy::RULES as $r)<option value="{{ $r }}" @selected($rule === $r)>{{ __('digital-products::admin.policies.rules.'.$r) }}</option>@endforeach</select></div>
        <div><label for="days" class="block text-sm font-medium">{{ __('digital-products::admin.policies.days') }}</label><p class="text-sm text-muted">{{ __('digital-products::admin.policies.days_help') }}</p>
            <input id="days" name="days" type="number" min="1" max="3650" value="{{ old('days', $policy?->days) }}" @disabled(! $can) class="mt-1 block w-32 rounded-md border border-line px-3 py-2">@error('days')<p class="text-sm text-danger">{{ $message }}</p>@enderror</div>
        <div class="space-y-2"><label class="flex items-center gap-2"><input type="hidden" name="revoke" value="0"><input type="checkbox" name="revoke" value="1" @checked(old('revoke', $policy?->revoke_on_full_refund ?? true)) @disabled(! $can)> {{ __('digital-products::admin.policies.revoke') }}</label>
            <label class="flex items-center gap-2"><input type="hidden" name="is_default" value="0"><input type="checkbox" name="is_default" value="1" @checked(old('is_default', $policy?->is_default ?? false)) @disabled(! $can)> {{ __('digital-products::admin.policies.make_default') }}</label></div>
        @foreach ($locales as $locale)
            @php $words = $policy?->words($locale); $own = $policy?->translations->firstWhere('locale', $locale); @endphp
            <fieldset class="space-y-2 rounded-lg border border-line p-4"><legend class="px-1 font-semibold">{{ strtoupper($locale) }}</legend>
                <div><label for="t-{{ $locale }}" class="block text-sm font-medium">{{ __('digital-products::admin.policies.words_title') }}</label><input id="t-{{ $locale }}" name="words[{{ $locale }}][title]" maxlength="120" value="{{ old('words.'.$locale.'.title', $own?->title) }}" @disabled(! $can) class="mt-1 block w-full rounded-md border border-line px-3 py-2"></div>
                <div><label for="x-{{ $locale }}" class="block text-sm font-medium">{{ __('digital-products::admin.policies.words_text') }}</label><textarea id="x-{{ $locale }}" name="words[{{ $locale }}][text]" rows="4" maxlength="2000" @disabled(! $can) class="mt-1 block w-full rounded-md border border-line px-3 py-2">{{ old('words.'.$locale.'.text', $own?->text) }}</textarea></div>
            </fieldset>
        @endforeach
        @if ($can)<x-platform::btn type="submit">{{ __('digital-products::admin.policies.save') }}</x-platform::btn>@endif
    </form>
</div>
@endsection
