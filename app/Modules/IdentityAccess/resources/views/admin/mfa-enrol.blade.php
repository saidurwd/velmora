@extends('layouts.admin-guest')

@section('title', __('identity-access::admin.mfa.enrol_title'))

@section('content')
<div class="space-y-6">
    <h1 class="text-2xl font-bold">{{ __('identity-access::admin.mfa.enrol_title') }}</h1>
    <p>{{ __('identity-access::admin.mfa.enrol_intro') }}</p>

    <div class="flex justify-center rounded-md border border-line p-4" role="img" aria-label="{{ __('identity-access::admin.mfa.qr_label') }}">{!! $qr_svg !!}</div>

    <details class="text-sm">
        <summary class="cursor-pointer">{{ __('identity-access::admin.mfa.cant_scan') }}</summary>
        <p class="mt-2 break-all font-mono">{{ $secret }}</p>
    </details>

    <x-platform::form.errors />
    <form method="POST" action="{{ route('admin.mfa.enrol') }}" class="space-y-4" novalidate>
        @csrf
        <x-platform::form.field name="code" inputmode="numeric" autocomplete="one-time-code" :label="__('identity-access::admin.mfa.code')" required />
        <x-platform::form.button class="w-full">{{ __('identity-access::admin.mfa.enrol_submit') }}</x-platform::form.button>
    </form>
</div>
@endsection
