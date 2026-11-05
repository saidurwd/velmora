@extends('layouts.account')

@section('title', __('customers::account.profile.title'))

@section('account')
<div class="max-w-xl space-y-6">
    <h1 class="text-2xl font-bold">{{ __('customers::account.profile.title') }}</h1>

    <form method="POST" action="{{ route('account.profile.update') }}" class="space-y-4" novalidate>
        @csrf @method('PUT')
        <x-platform::form.field name="name" :value="$profile?->name" :label="__('customers::account.profile.name')" autocomplete="name" required />
        <x-platform::form.field name="birth_date" type="date" :value="$profile?->birthDate" :label="__('customers::account.profile.birth_date')" :help="__('customers::account.profile.birth_help')" autocomplete="bday" />
        <p class="text-sm"><a class="underline" href="{{ route('account.preferences') }}">{{ __('customers::account.profile.marketing_moved') }}</a></p>
        <x-platform::form.button>{{ __('customers::account.profile.save') }}</x-platform::form.button>
    </form>

    <section class="border-t border-line pt-6">
        <h2 class="font-semibold">{{ __('customers::account.profile.language') }}</h2>
        <x-platform::language-switcher />
    </section>
</div>
@endsection
