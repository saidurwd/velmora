@extends('layouts.storefront')

@section('title', __('orders::checkout.details_title'))

@php($fmt = fn ($m) => \App\Modules\Platform\Support\Fmt::money($m))

@section('content')
<div class="grid gap-8 lg:grid-cols-[1fr_22rem]">
    <div class="space-y-6">
        <nav aria-label="{{ __('orders::checkout.steps') }}"><ol class="flex gap-4 text-sm text-muted">
            <li aria-current="step" class="font-semibold text-ink">1. {{ __('orders::checkout.step_details') }}</li><li>2. {{ __('orders::checkout.step_review') }}</li><li>3. {{ __('orders::checkout.step_done') }}</li>
        </ol></nav>
        <h1 class="text-3xl font-bold">{{ __('orders::checkout.details_title') }}</h1>
        @error('checkout')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror

        <form method="POST" action="{{ route('checkout.save', ['locale' => app()->getLocale()]) }}" class="space-y-6" novalidate>
            @csrf
            @if ($needsDelivery)
                @if ($saved !== [])
                    <fieldset class="space-y-3">
                        <legend class="text-lg font-semibold">{{ __('orders::checkout.deliver_to') }}</legend>
                        @error('saved_address')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                        @foreach ($saved as $a)
                            <label class="flex gap-3 rounded-lg border border-line p-3">
                                <input type="radio" name="saved_address" value="{{ $a->publicId }}" class="mt-1" @checked(old('saved_address', ($draft['address']['id'] ?? null) ?: ($a->isDefault ? $a->publicId : null)) === $a->publicId || ($loop->first && ! collect($saved)->contains(fn ($s) => $s->isDefault) && old('saved_address') === null)) required>
                                <span><span class="block font-medium">{{ $a->label ?: $a->recipient }}</span><span class="block text-sm text-muted">{{ $a->recipient }} · {{ $a->phone }}</span><span class="block text-sm">{{ $a->oneLine(app()->getLocale()) }}</span></span>
                            </label>
                        @endforeach
                        <a class="underline" href="{{ route('checkout.details', ['locale' => app()->getLocale(), 'new' => 1]) }}">{{ __('orders::checkout.use_another_address') }}</a>
                    </fieldset>
                @else
                    <fieldset class="space-y-3">
                        <legend class="text-lg font-semibold">{{ __('orders::checkout.deliver_to') }}</legend>
                        @include('customers::partials.address-fields', ['address' => null, 'geo' => $geo])
                        @if ($user)<x-platform::form.checkbox name="save_address" :label="__('orders::checkout.save_address')" />@endif
                        @if ($hasSaved)<a class="underline" href="{{ route('checkout.details', ['locale' => app()->getLocale()]) }}">{{ __('orders::checkout.use_saved_address') }}</a>@endif
                    </fieldset>
                @endif
            @else
                <fieldset class="space-y-3">
                    <legend class="text-lg font-semibold">{{ __('orders::checkout.contact') }}</legend>
                    <x-platform::form.field name="contact_name" :value="old('contact_name', $draft['contact_name'] ?? $user?->name)" :label="__('orders::checkout.name')" autocomplete="name" required />
                    <x-platform::form.field name="contact_phone" type="tel" inputmode="tel" :value="old('contact_phone', $draft['contact_phone'] ?? $user?->phone)" :label="__('orders::checkout.phone')" :help="__('customers::account.addresses.phone_help')" autocomplete="tel" required />
                </fieldset>
            @endif

            <x-platform::form.field name="email" type="email" :value="old('email', $draft['contact_email'] ?? $user?->email)" :label="__('orders::checkout.email')" :help="__('orders::checkout.email_help')" autocomplete="email" />
            <x-platform::form.button>{{ __('orders::checkout.continue') }}</x-platform::form.button>
        </form>
    </div>

    <aside aria-labelledby="mini-h" class="h-fit space-y-2 rounded-lg border border-line p-4">
        <h2 id="mini-h" class="text-lg font-semibold">{{ __('orders::checkout.your_order') }}</h2>
        <ul class="space-y-1 text-sm">@foreach ($cart->lines as $l)<li class="flex justify-between gap-2"><span>{{ $l->name }} × {{ \App\Modules\Platform\Support\Fmt::number($l->quantity) }}</span><span>{{ $l->lineTotal ? $fmt($l->lineTotal) : '' }}</span></li>@endforeach</ul>
        <p class="flex justify-between border-t border-line pt-2 font-semibold"><span>{{ __('cart::cart.subtotal') }}</span><span>{{ $fmt($cart->subtotal) }}</span></p>
        <a class="text-sm underline" href="{{ route('cart.show', ['locale' => app()->getLocale()]) }}">{{ __('orders::checkout.edit_cart') }}</a>
    </aside>
</div>
@endsection
