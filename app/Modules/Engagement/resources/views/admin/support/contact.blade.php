@extends('layouts.admin')

@section('title', __('engagement::support.contact_settings_title'))

@section('content')
<div class="max-w-xl space-y-6">
    <h1 class="text-2xl font-bold">{{ __('engagement::support.contact_settings_title') }}</h1>
    <p class="text-sm text-muted">{{ __('engagement::support.contact_settings_help') }}</p>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    <form method="POST" action="{{ route('admin.support.contact.update') }}" class="space-y-4" novalidate>
        @csrf @method('PUT')
        <x-platform::form.field name="phone" :value="old('phone', $phone)" :label="__('engagement::support.phone_label')" maxlength="30" />
        <x-platform::form.field name="email" type="email" :value="old('email', $email)" :label="__('engagement::support.email_label')" maxlength="120" />
        @foreach ($hours as $locale => $value)
            <x-platform::form.field :name="'hours['.$locale.']'" :value="old('hours.'.$locale, $value)" :label="__('engagement::support.hours_label').' ('.strtoupper($locale).')'" maxlength="120" />
        @endforeach
        <x-platform::form.field name="response_hours" :value="old('response_hours', $responseHours)" :label="__('engagement::support.response_hours_label')" inputmode="numeric" :help="__('engagement::support.response_hours_help')" required />
        <x-platform::form.button>{{ __('engagement::support.save') }}</x-platform::form.button>
    </form>
</div>
@endsection
