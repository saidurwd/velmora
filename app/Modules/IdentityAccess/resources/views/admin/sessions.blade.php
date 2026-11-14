@extends('layouts.admin')

@section('title', __('identity-access::admin.sessions.title'))

@section('content')
<div class="max-w-4xl space-y-8">
    <h1 class="text-2xl font-bold">{{ __('identity-access::admin.sessions.title') }}</h1>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif

    <table class="w-full text-start text-sm">
        <caption class="sr-only">{{ __('identity-access::admin.sessions.title') }}</caption>
        <thead><tr class="border-b border-line">
            <th scope="col" class="py-2 text-start">{{ __('identity-access::admin.sessions.device') }}</th>
            <th scope="col" class="py-2 text-start">{{ __('identity-access::admin.sessions.ip') }}</th>
            <th scope="col" class="py-2 text-start">{{ __('identity-access::admin.sessions.last_seen') }}</th>
        </tr></thead>
        <tbody>
        @foreach ($sessions as $session)
            <tr class="border-b border-line">
                <td class="py-2">{{ \App\Modules\Platform\Support\UserAgentLabel::label($session->user_agent) }} @if ($session->session_id_hash === $currentHash)<x-platform::badge tone="brand">{{ __('identity-access::admin.sessions.this_device') }}</x-platform::badge>@endif
                    <span class="block text-xs text-muted">{{ \Illuminate\Support\Str::limit((string) $session->user_agent, 80) }}</span></td>
                <td class="py-2">{{ $session->ip }}</td>
                <td class="py-2">{{ \App\Modules\Platform\Support\Fmt::relative(\Carbon\CarbonImmutable::parse($session->last_seen_at, 'UTC')) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <form method="POST" action="{{ route('admin.sessions.revoke-others') }}">
        @csrf
        <x-platform::form.button variant="secondary">{{ __('identity-access::admin.sessions.revoke_others') }}</x-platform::form.button>
    </form>

    <section aria-labelledby="recent" class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 id="recent" class="text-lg font-semibold">{{ __('platform::security.logins.recent') }}</h2>
            @can('audit.view')<a class="text-sm underline" href="{{ route('admin.security.logins', ['person' => auth('admin')->id()]) }}">{{ __('platform::security.logins.all') }}</a>@endcan
        </div>
        <p class="text-sm text-muted">{{ __('platform::security.logins.recent_intro') }}</p>
        <div class="overflow-x-auto" tabindex="0" role="region" aria-label="{{ __('platform::security.logins.recent') }}">
            <table class="w-full text-sm">
                <caption class="sr-only">{{ __('platform::security.logins.recent') }}</caption>
                <thead><tr class="border-b border-line">
                    <th scope="col" class="py-2 pe-3 text-start">{{ __('platform::security.columns.time') }}</th>
                    <th scope="col" class="py-2 pe-3 text-start">{{ __('platform::security.columns.what') }}</th>
                    <th scope="col" class="py-2 pe-3 text-start">{{ __('platform::security.columns.device') }}</th>
                    <th scope="col" class="py-2 text-start">{{ __('platform::security.columns.ip') }}</th>
                </tr></thead>
                <tbody>
                @forelse ($recent as $r)
                    @php($result = \App\Modules\Platform\Support\AuditCatalog::SIGN_IN[$r->action] ?? 'success')
                    <tr class="border-b border-line">
                        <td class="py-2 pe-3 whitespace-nowrap">{{ \App\Modules\Platform\Support\Fmt::dateTime(\Carbon\CarbonImmutable::parse($r->created_at, 'UTC')) }}</td>
                        <td class="py-2 pe-3"><x-platform::badge :tone="['success' => 'success', 'failure' => 'warning', 'locked' => 'danger', 'signout' => 'neutral'][$result]">{{ \App\Modules\Platform\Support\AuditCatalog::loginLabel($r->action) }}</x-platform::badge></td>
                        <td class="py-2 pe-3">{{ \App\Modules\Platform\Support\UserAgentLabel::label($r->user_agent) }}</td>
                        <td class="py-2 font-mono">{{ \App\Modules\Platform\Services\AuditLogQuery::maskIp($r->ip) ?: '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-6 text-center text-muted">{{ __('platform::security.logins.empty') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
@endsection
