@extends('layouts.admin')

@section('title', __('privacy::admin.detail'))

@section('content')
@php $fmt = fn ($d) => $d === null ? '' : \App\Modules\Platform\Support\Fmt::dateTime(\Carbon\CarbonImmutable::parse($d, 'UTC')); @endphp
<div class="max-w-3xl space-y-6">
    <p><a class="underline" href="{{ route('admin.privacy.index') }}">{{ __('privacy::admin.back') }}</a></p>
    <h1 class="text-2xl font-bold">{{ __('privacy::admin.kinds.'.$request->type) }}</h1>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    @error('request')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror

    <dl class="grid gap-x-6 gap-y-2 sm:grid-cols-[12rem_1fr]">
        <dt class="font-medium">{{ __('privacy::admin.customer') }}</dt><dd>{{ $customer?->name ?? __('privacy::admin.customer_gone') }} <span class="text-xs text-muted">{{ $customer?->publicId }}</span></dd>
        <dt class="font-medium">{{ __('privacy::admin.status') }}</dt><dd><x-platform::badge tone="neutral">{{ __('privacy::admin.states.'.$request->status) }}</x-platform::badge></dd>
        <dt class="font-medium">{{ __('privacy::admin.asked') }}</dt><dd>{{ $fmt($request->requested_at) }}</dd>
        @if ($request->reason)<dt class="font-medium">{{ __('privacy::admin.their_reason') }}</dt><dd>{{ $request->reason }}</dd>@endif
        @if ($request->decided_at)<dt class="font-medium">{{ __('privacy::admin.decided') }}</dt><dd>{{ $fmt($request->decided_at) }}@if ($request->decision_reason) — {{ $request->decision_reason }}@endif</dd>@endif
        @if ($request->failure)<dt class="font-medium">{{ __('privacy::admin.failure') }}</dt><dd>{{ $request->failure }}</dd>@endif
    </dl>

    @if ($request->type === 'erasure' && in_array($request->status, ['requested', 'blocked'], true))
        <section class="space-y-2 border-t border-line pt-6">
            <h2 class="font-semibold">{{ __('privacy::admin.blockers') }}</h2>
            @if ($blockers === [])
                <p>{{ __('privacy::admin.no_blockers') }}</p>
            @else
                <ul class="list-disc pl-5">@foreach ($blockers as $code)<li>{{ __('privacy::errors.blockers.'.$code) }}</li>@endforeach</ul>
            @endif
            <p class="text-sm text-muted">{{ __('privacy::admin.retention', ['years' => (int) app(\App\Modules\Platform\Support\Settings::class)->int('privacy.financial_retention_years', (int) config('privacy.financial_retention_years', 6))]) }}</p>
        </section>

        @if ($can)
        <div class="grid gap-6 border-t border-line pt-6 md:grid-cols-2">
            <form method="POST" action="{{ route('admin.privacy.approve', ['publicId' => $request->public_id]) }}" class="space-y-3">@csrf
                <h2 class="font-semibold">{{ __('privacy::admin.approve') }}</h2>
                <p class="text-sm text-muted">{{ __('privacy::admin.approve_help') }}</p>
                <div><label for="note" class="block text-sm font-medium">{{ __('privacy::admin.note') }}</label><input id="note" name="reason" maxlength="255" class="mt-1 block w-full rounded-md border border-line px-3 py-2"></div>
                <x-platform::btn type="submit" variant="danger" :disabled="$blockers !== []">{{ __('privacy::admin.approve') }}</x-platform::btn>
            </form>
            <form method="POST" action="{{ route('admin.privacy.reject', ['publicId' => $request->public_id]) }}" class="space-y-3">@csrf
                <h2 class="font-semibold">{{ __('privacy::admin.reject') }}</h2>
                <p class="text-sm text-muted">{{ __('privacy::admin.reject_help') }}</p>
                <div><label for="why" class="block text-sm font-medium">{{ __('privacy::admin.reason') }}</label><input id="why" name="reason" maxlength="255" required class="mt-1 block w-full rounded-md border border-line px-3 py-2"></div>
                <x-platform::btn type="submit" variant="secondary">{{ __('privacy::admin.reject') }}</x-platform::btn>
            </form>
        </div>
        @endif
    @endif
</div>
@endsection
