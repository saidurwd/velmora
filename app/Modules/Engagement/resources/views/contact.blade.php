@extends('layouts.storefront')

@section('title', __('engagement::support.contact_title'))

@section('content')
<div class="max-w-2xl space-y-6">
    <h1 class="text-2xl font-bold">{{ __('engagement::support.contact_title') }}</h1>
    <p>{{ __('engagement::support.contact_intro') }}</p>
    @if ($phone === '' && $email === '')
        <p class="text-muted">{{ __('engagement::support.contact_none') }}</p>
    @else
        <dl class="grid gap-x-6 gap-y-2 sm:grid-cols-[10rem_1fr]">
            @if ($phone !== '')<dt class="font-medium">{{ __('engagement::support.phone_label') }}</dt><dd><a class="underline" href="tel:{{ preg_replace('/[^0-9+]/', '', $phone) }}">{{ $phone }}</a></dd>@endif
            @if ($email !== '')<dt class="font-medium">{{ __('engagement::support.email_label') }}</dt><dd><a class="underline" href="mailto:{{ $email }}">{{ $email }}</a></dd>@endif
            @if ($hours !== '')<dt class="font-medium">{{ __('engagement::support.hours_label') }}</dt><dd>{{ $hours }}</dd>@endif
        </dl>
    @endif
    <p><a class="underline" href="{{ route('help') }}">{{ __('engagement::support.help_link') }}</a> · <a class="underline" href="{{ route('account.support') }}">{{ __('engagement::support.signed_in_link') }}</a></p>
</div>
@endsection
