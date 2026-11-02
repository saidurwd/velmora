@extends('layouts.admin')

@section('title', __('digital-products::admin.entitlements.title'))

@php $fmt = fn ($d) => $d === null ? __('digital-products::admin.entitlements.never') : \App\Modules\Platform\Support\Fmt::dateTime($d); @endphp

@section('content')
<div class="max-w-5xl space-y-6">
    <h1 class="text-2xl font-bold">{{ __('digital-products::admin.entitlements.title') }}</h1>
    <p class="text-sm text-muted">{{ __('digital-products::admin.entitlements.help') }}</p>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    @error('entitlement')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror
    <form method="GET" action="{{ route('admin.digital.entitlements.index') }}" class="flex items-end gap-3">
        <div class="flex-1"><label for="order" class="block text-sm font-medium">{{ __('digital-products::admin.entitlements.order') }}</label><input id="order" name="order" value="{{ $number }}" maxlength="20" class="block w-full rounded-md border border-line px-3 py-2"></div>
        <x-platform::btn type="submit" size="sm">{{ __('digital-products::admin.find') }}</x-platform::btn>
    </form>
    @if ($number !== '' && $order === null)
        <p class="text-muted">{{ __('digital-products::admin.entitlements.no_order') }}</p>
    @elseif ($order !== null && $rows === [])
        <p class="text-muted">{{ __('digital-products::admin.entitlements.none') }}</p>
    @endif
    @foreach ($rows as $r)
        @php $e = $r['right']; $active = $e->status->value === 'active'; @endphp
        <section class="space-y-3 rounded-lg border border-line p-4" aria-label="{{ $r['product'] }}">
            <div class="flex flex-wrap items-start justify-between gap-2"><div><h2 class="font-semibold">{{ $r['product'] }} <span class="text-sm font-normal text-muted">({{ $r['sku'] }})</span></h2>
                <p class="text-sm text-muted">{{ __('digital-products::admin.entitlements.order') }} {{ $order['number'] }}</p></div>
                <x-platform::badge :tone="$active ? 'success' : 'danger'">{{ __('digital-products::admin.entitlements.status.'.$e->status->value) }}</x-platform::badge></div>
            <dl class="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                <div><dt class="text-muted">{{ __('digital-products::admin.entitlements.downloads') }}</dt><dd>{{ $e->downloads_used }} / {{ $e->download_limit ?? __('digital-products::admin.entitlements.unlimited') }}</dd></div>
                <div><dt class="text-muted">{{ __('digital-products::admin.entitlements.expires') }}</dt><dd>{{ $fmt($e->expires_at) }}</dd></div>
                <div><dt class="text-muted">{{ __('digital-products::admin.entitlements.granted') }}</dt><dd>{{ $fmt($e->granted_at) }}</dd></div>
                <div><dt class="text-muted">{{ __('digital-products::admin.entitlements.first_used') }}</dt><dd>{{ $e->first_used_at === null ? __('digital-products::admin.entitlements.not_yet') : $fmt($e->first_used_at) }}</dd></div>
                <div><dt class="text-muted">{{ __('digital-products::admin.entitlements.keys') }}</dt><dd>{{ $r['keys'] }} / {{ $e->keys_needed }}</dd></div>
                @if (! $active)<div><dt class="text-muted">{{ __('digital-products::admin.entitlements.revoked_why') }}</dt><dd>{{ $e->revoked_reason }} ({{ $fmt($e->revoked_at) }})</dd></div>@endif
            </dl>
            @if ($r['verdict'])
                <p class="rounded-md bg-surface-alt p-3 text-sm"><strong>{{ $r['verdict']['refundable'] ? __('digital-products::admin.entitlements.refundable') : __('digital-products::admin.entitlements.not_refundable') }}</strong> — {{ __('digital-products::admin.entitlements.reason.'.$r['verdict']['reason']) }}. {{ __('digital-products::admin.entitlements.advice') }}</p>
            @endif
            @if ($can && $active)
            <div class="grid gap-3 sm:grid-cols-2">
                <form method="POST" action="{{ route('admin.digital.entitlements.adjust', $e->public_id) }}" class="flex flex-wrap items-end gap-2">@csrf
                    <div><label for="a-{{ $e->id }}" class="block text-xs">{{ __('digital-products::admin.entitlements.action') }}</label>
                        <select id="a-{{ $e->id }}" name="action" class="rounded-md border border-line px-2 py-1"><option value="extend">{{ __('digital-products::admin.entitlements.extend') }}</option><option value="add_downloads">{{ __('digital-products::admin.entitlements.add_downloads') }}</option><option value="reset">{{ __('digital-products::admin.entitlements.reset') }}</option></select></div>
                    <div><label for="n-{{ $e->id }}" class="block text-xs">{{ __('digital-products::admin.entitlements.amount') }}</label><input id="n-{{ $e->id }}" name="amount" type="number" min="1" max="3650" class="w-24 rounded-md border border-line px-2 py-1"></div>
                    <x-platform::btn type="submit" size="sm" variant="secondary">{{ __('digital-products::admin.entitlements.apply') }}</x-platform::btn>
                </form>
                <form method="POST" action="{{ route('admin.digital.entitlements.revoke', $e->public_id) }}" class="flex flex-wrap items-end gap-2">@csrf
                    <div class="flex-1"><label for="r-{{ $e->id }}" class="block text-xs">{{ __('digital-products::admin.entitlements.reason_label') }}</label><input id="r-{{ $e->id }}" name="reason" required maxlength="255" class="w-full rounded-md border border-line px-2 py-1"></div>
                    <x-platform::btn type="submit" size="sm" variant="secondary">{{ __('digital-products::admin.entitlements.revoke') }}</x-platform::btn>
                </form>
            </div>
            @endif
        </section>
    @endforeach
</div>
@endsection
