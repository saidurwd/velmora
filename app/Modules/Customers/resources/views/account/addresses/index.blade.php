@extends('layouts.account')

@section('title', __('customers::account.addresses.title'))

@section('account')
<div class="space-y-6">
    <div class="flex items-center justify-between gap-4">
        <h1 class="text-2xl font-bold">{{ __('customers::account.addresses.title') }}</h1>
        <x-platform::btn :href="route('account.addresses.create')">{{ __('customers::account.addresses.add') }}</x-platform::btn>
    </div>

    @forelse ($addresses as $a)
        <article class="space-y-2 rounded-lg border border-line p-4" aria-labelledby="addr-{{ $a->publicId }}">
            <div class="flex flex-wrap items-center gap-2">
                <h2 id="addr-{{ $a->publicId }}" class="font-semibold">{{ $a->label ?: $a->recipient }}</h2>
                @if ($a->isDefault)<x-platform::badge tone="brand">{{ __('customers::account.addresses.default') }}</x-platform::badge>@endif
            </div>
            <p>{{ $a->recipient }} · <span dir="ltr">{{ '0'.substr($a->phone, 4) }}</span></p>
            <p class="text-muted">{{ $a->oneLine(app()->getLocale()) }}</p>
            @if ($a->landmark)<p class="text-sm text-muted">{{ $a->landmark }}</p>@endif
            <div class="flex flex-wrap gap-4 pt-1">
                <a class="underline" href="{{ route('account.addresses.edit', $a->publicId) }}">{{ __('customers::account.addresses.edit') }}</a>
                @unless ($a->isDefault)
                    <form method="POST" action="{{ route('account.addresses.default', $a->publicId) }}">@csrf<button class="underline">{{ __('customers::account.addresses.make_default') }}</button></form>
                @endunless
                <form method="POST" action="{{ route('account.addresses.destroy', $a->publicId) }}" onsubmit="return confirm(@js(__('customers::account.addresses.confirm_delete')))">@csrf @method('DELETE')<button class="underline">{{ __('customers::account.addresses.delete') }}</button></form>
            </div>
        </article>
    @empty
        <x-platform::empty-state :title="__('customers::account.addresses.empty_title')" :text="__('customers::account.addresses.empty_text')" />
    @endforelse
</div>
@endsection
