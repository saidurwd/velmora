@extends('layouts.admin')

@section('title', __('shipping::admin.methods.title'))

@php $fmt = fn (int $minor) => \App\Modules\Platform\Support\Fmt::money(\App\Modules\Platform\DTOs\Money::of($minor)); @endphp

@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between gap-4">
        <h1 class="text-2xl font-bold">{{ __('shipping::admin.methods.title') }}</h1>
        @can('shipping.manage')<a href="{{ route('admin.shipping.methods.create') }}" class="inline-flex min-h-11 items-center rounded-md bg-brand-600 px-5 py-2 font-medium text-white hover:bg-brand-700">{{ __('shipping::admin.methods.add') }}</a>@endcan
    </div>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    <div class="overflow-x-auto">
    <table class="w-full text-sm">
        <caption class="sr-only">{{ __('shipping::admin.methods.title') }}</caption>
        <thead><tr class="border-b border-line">
            @foreach (['name', 'code', 'cod', 'cod_fee', 'free_over', 'status'] as $c)<th scope="col" class="py-2 pe-3 text-start">{{ __('shipping::admin.methods.cols.'.$c) }}</th>@endforeach
        </tr></thead>
        <tbody>
        @foreach ($methods as $m)
            <tr class="border-b border-line">
                <td class="py-2 pe-3"><a class="underline" href="{{ route('admin.shipping.methods.edit', $m) }}">{{ $m->translate('name') ?? $m->code }}</a></td>
                <td class="py-2 pe-3">{{ $m->code }}</td>
                <td class="py-2 pe-3">{{ $m->cod_available ? __('shipping::admin.yes') : __('shipping::admin.no') }}</td>
                <td class="py-2 pe-3">{{ $m->cod_fee_minor > 0 ? $fmt($m->cod_fee_minor) : __('shipping::admin.methods.none') }}</td>
                <td class="py-2 pe-3">{{ $m->free_over_minor !== null ? $fmt($m->free_over_minor) : __('shipping::admin.methods.none') }}</td>
                <td class="py-2 pe-3">{{ $m->is_active ? __('shipping::admin.active') : __('shipping::admin.inactive') }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    </div>
</div>
@endsection
