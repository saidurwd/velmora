@extends('layouts.admin')

@section('title', __('digital-products::admin.policies.title'))

@section('content')
<div class="max-w-4xl space-y-6">
    <div class="flex items-center justify-between"><h1 class="text-2xl font-bold">{{ __('digital-products::admin.policies.title') }}</h1>
        @if (auth('admin')->user()?->can('digital.manage'))
            <x-platform::btn :href="route('admin.digital.policies.create')" size="sm">{{ __('digital-products::admin.policies.new') }}</x-platform::btn>
        @endif
    </div>
    <p class="text-sm text-muted">{{ __('digital-products::admin.policies.help') }}</p>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    <div class="overflow-x-auto"><table class="w-full text-sm">
        <caption class="sr-only">{{ __('digital-products::admin.policies.title') }}</caption>
        <thead><tr class="border-b border-line">@foreach (['policy', 'rule', 'revoke'] as $col)<th scope="col" class="py-2 pe-3 text-start">{{ __('digital-products::admin.policies.cols.'.$col) }}</th>@endforeach</tr></thead>
        <tbody>
        @foreach ($policies as $p)
            <tr class="border-b border-line"><td class="py-2 pe-3"><a class="underline" href="{{ route('admin.digital.policies.edit', $p->public_id) }}">{{ $p->words(app()->getLocale())['title'] ?? $p->code }}</a>@if ($p->is_default) <x-platform::badge tone="brand">{{ __('digital-products::admin.policies.default') }}</x-platform::badge>@endif</td>
                <td class="py-2 pe-3">{{ __('digital-products::admin.policies.rules.'.$p->rule) }}@if ($p->days) ({{ __('digital-products::admin.policies.days_n', ['n' => $p->days]) }})@endif</td>
                <td class="py-2 pe-3">{{ $p->revoke_on_full_refund ? __('digital-products::admin.yes') : __('digital-products::admin.no') }}</td></tr>
        @endforeach
        </tbody>
    </table></div>
</div>
@endsection
