@extends('layouts.account')

@section('title', __('customers::account.addresses.title'))

@section('account')
<div class="max-w-xl space-y-6">
    <h1 class="text-2xl font-bold">{{ $address ? __('customers::account.addresses.edit_title') : __('customers::account.addresses.add_title') }}</h1>

    <form method="POST" action="{{ $address ? route('account.addresses.update', $address->publicId) : route('account.addresses.store') }}" class="space-y-4" novalidate>
        @csrf
        @if ($address) @method('PUT') @endif
        <x-platform::form.field name="label" :value="$address?->label" :label="__('customers::account.addresses.label')" :help="__('customers::account.addresses.label_help')" />
        @include('customers::partials.address-fields', ['address' => $address, 'geo' => $geo])
        <x-platform::form.checkbox name="make_default" :label="__('customers::account.addresses.make_default')" :checked="$address?->isDefault ?? false" />
        <x-platform::form.button>{{ __('customers::account.addresses.save') }}</x-platform::form.button>
    </form>
</div>
@endsection
