@extends('layouts.account')

@section('title', __('customers::account.profile.title'))

@section('account')
<div class="max-w-xl space-y-6">
    <h1 class="text-2xl font-bold">{{ __('customers::account.profile.title') }}</h1>

    <form method="POST" action="{{ route('account.profile.update') }}" class="space-y-4" novalidate>
        @csrf @method('PUT')
        <x-platform::form.field name="name" :value="$profile?->name" :label="__('customers::account.profile.name')" autocomplete="name" required />
        <x-platform::form.field name="birth_date" type="date" :value="$profile?->birthDate" :label="__('customers::account.profile.birth_date')" :help="__('customers::account.profile.birth_help')" autocomplete="bday" />
        <label class="flex items-start gap-2">
            <input type="checkbox" name="marketing_consent" value="1" @checked(old('marketing_consent', $profile?->marketingConsent)) class="mt-1 size-4">
            <span>{{ __('customers::account.profile.marketing') }}</span>
        </label>
        <x-platform::form.button>{{ __('customers::account.profile.save') }}</x-platform::form.button>
    </form>

    <section class="border-t border-line pt-6">
        <h2 class="font-semibold">{{ __('customers::account.profile.language') }}</h2>
        <x-platform::language-switcher />
    </section>
</div>
@endsection
