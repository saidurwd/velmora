@extends('layouts.account')

@section('title', __('privacy::account.title'))

@section('account')
@php
    $fmt = fn ($d) => \App\Modules\Platform\Support\Fmt::dateTime(\Carbon\CarbonImmutable::parse($d, 'UTC'));
    $openErasure = collect($requests)->first(fn ($r) => $r->type === 'erasure' && in_array($r->status, ['requested', 'approved', 'blocked'], true));
    $openExport = collect($requests)->contains(fn ($r) => $r->type === 'export' && $r->status === 'requested');
@endphp
<div class="max-w-2xl space-y-8">
    <div class="space-y-2">
        <h1 class="text-2xl font-bold">{{ __('privacy::account.title') }}</h1>
        <p>{{ __('privacy::account.intro') }}</p>
    </div>

    <section class="space-y-3" aria-labelledby="export-h">
        <h2 id="export-h" class="text-lg font-semibold">{{ __('privacy::account.export_title') }}</h2>
        <p>{{ __('privacy::account.export_help') }}</p>
        <form method="POST" action="{{ route('account.privacy.export') }}">@csrf
            <x-platform::btn type="submit" :disabled="$openExport">{{ __('privacy::account.export_button') }}</x-platform::btn>
        </form>
    </section>

    <section class="space-y-3 border-t border-line pt-6" aria-labelledby="erase-h">
        <h2 id="erase-h" class="text-lg font-semibold">{{ __('privacy::account.erasure_title') }}</h2>
        <p>{{ __('privacy::account.erasure_help') }}</p>
        @if ($openErasure === null)
            <form method="POST" action="{{ route('account.privacy.erasure') }}" class="space-y-4" novalidate>@csrf
                <div>
                    <label for="reason" class="block text-sm font-medium">{{ __('privacy::account.erasure_reason') }}</label>
                    <textarea id="reason" name="reason" rows="3" maxlength="500" class="mt-1 block w-full rounded-md border border-line px-3 py-2">{{ old('reason') }}</textarea>
                </div>
                <label class="flex items-start gap-2"><input type="checkbox" name="understand" value="1" required class="mt-1 size-4"><span>{{ __('privacy::account.erasure_understand') }}</span></label>
                <x-platform::btn type="submit" variant="danger">{{ __('privacy::account.erasure_button') }}</x-platform::btn>
            </form>
        @elseif ($blockers !== [])
            <x-platform::alert type="info">
                {{ __('privacy::account.waiting_blockers') }}
                <ul class="mt-1 list-disc pl-5">@foreach ($blockers as $code)<li>{{ __('privacy::errors.blockers.'.$code) }}</li>@endforeach</ul>
            </x-platform::alert>
        @endif
    </section>

    <section class="space-y-3 border-t border-line pt-6" aria-labelledby="req-h">
        <h2 id="req-h" class="text-lg font-semibold">{{ __('privacy::account.requests') }}</h2>
        @if ($requests === [])
            <p class="text-muted">{{ __('privacy::account.none') }}</p>
        @else
            <ul class="space-y-3">
                @foreach ($requests as $r)
                    <li class="rounded-md border border-line p-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="font-medium">{{ __('privacy::account.type.'.$r->type) }}</span>
                            <x-platform::badge :tone="in_array($r->status, ['ready', 'done'], true) ? 'success' : (in_array($r->status, ['failed', 'rejected'], true) ? 'danger' : 'neutral')">{{ __('privacy::account.status.'.$r->status) }}</x-platform::badge>
                        </div>
                        <div class="text-sm text-muted">{{ __('privacy::account.when') }}: {{ $fmt($r->requested_at) }}</div>
                        @if ($r->status === 'ready')
                            <div class="mt-2 flex flex-wrap items-center gap-3 text-sm">
                                <a class="underline" href="{{ route('account.privacy.download', ['publicId' => $r->public_id]) }}">{{ __('privacy::account.download') }}</a>
                                <span class="text-muted">{{ __('privacy::account.until', ['date' => $fmt($r->expires_at)]) }}</span>
                            </div>
                        @endif
                        @if ($r->status === 'rejected' && $r->decision_reason)<p class="mt-2 text-sm">{{ __('privacy::account.why', ['reason' => $r->decision_reason]) }}</p>@endif
                        @if ($r->type === 'erasure' && $r->status === 'requested')
                            <form method="POST" action="{{ route('account.privacy.erasure.cancel', ['publicId' => $r->public_id]) }}" class="mt-2">@csrf
                                <x-platform::btn type="submit" size="sm" variant="secondary">{{ __('privacy::account.cancel') }}</x-platform::btn>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
@endsection
