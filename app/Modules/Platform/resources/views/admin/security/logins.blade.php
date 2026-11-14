@extends('layouts.admin')

@php
    use App\Modules\Platform\Services\AuditLogQuery;
    use App\Modules\Platform\Support\AuditCatalog;
    use App\Modules\Platform\Support\Fmt;
    use App\Modules\Platform\Support\UserAgentLabel;
    use Carbon\CarbonImmutable;

    $tone = ['success' => 'success', 'failure' => 'warning', 'locked' => 'danger', 'signout' => 'neutral'];
    // how the person signed in, and where: from the action and what the trail kept with it
    $how = function (\stdClass $r): array {
        $after = json_decode((string) $r->after, true) ?: [];
        $method = match ($r->action) {
            'auth.mfa_passed', 'auth.mfa_failed' => 'two_step',
            'auth.recovery_code_used' => 'recovery',
            'auth.otp_failed' => 'phone_code',
            'auth.login', 'auth.api_login' => ($after['method'] ?? 'password') === 'phone_otp' ? 'phone_code' : 'password',
            'auth.login_failed', 'auth.account_locked' => 'password',
            default => '',
        };
        $where = match (true) {
            str_starts_with($r->action, 'auth.api_') => 'app',
            ($after['area'] ?? null) === 'staff', in_array($r->action, ['auth.mfa_passed', 'auth.mfa_failed', 'auth.recovery_code_used'], true), $r->actor_type === 'staff' => 'staff',
            default => 'shop',
        };

        return [$method, $where];
    };
@endphp

@section('title', __('platform::security.logins.title'))

@section('content')
<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold">{{ __('platform::security.logins.title') }}</h1>
        <p class="mt-1 text-sm text-muted">{{ __('platform::security.logins.intro') }}</p>
    </div>
    <x-platform::form.errors />

    <form method="GET" action="{{ route('admin.security.logins') }}" class="grid gap-3 rounded-lg border border-line p-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6" novalidate>
        <div><label for="result" class="block text-sm font-medium">{{ __('platform::security.filters.result') }}</label>
            <select id="result" name="result" class="mt-1 w-full rounded-md border border-line px-3 py-2"><option value="">{{ __('platform::security.filters.any') }}</option>@foreach (['success', 'failure', 'locked', 'signout'] as $k)<option value="{{ $k }}" @selected($filters['result'] === $k)>{{ __('platform::security.result.'.$k) }}</option>@endforeach</select></div>
        <div><label for="kind" class="block text-sm font-medium">{{ __('platform::security.filters.kind') }}</label>
            <select id="kind" name="kind" class="mt-1 w-full rounded-md border border-line px-3 py-2"><option value="">{{ __('platform::security.filters.everyone') }}</option><option value="staff" @selected($filters['kind'] === 'staff')>{{ __('platform::security.kind.staff') }}</option><option value="customer" @selected($filters['kind'] === 'customer')>{{ __('platform::security.kind.customers') }}</option></select></div>
        <div><label for="person" class="block text-sm font-medium">{{ __('platform::security.filters.staff') }}</label>
            <select id="person" name="person" class="mt-1 w-full rounded-md border border-line px-3 py-2"><option value="">{{ __('platform::security.filters.any_staff') }}</option>@foreach ($staff as $p)<option value="{{ $p->id }}" @selected($filters['person'] === (string) $p->id)>{{ $p->name }}</option>@endforeach</select></div>
        <div><label for="customer" class="block text-sm font-medium">{{ __('platform::security.filters.customer') }}</label><input id="customer" name="customer" value="{{ $filters['customer'] }}" maxlength="100" autocomplete="off" aria-describedby="customer-help" class="mt-1 w-full rounded-md border border-line px-3 py-2">
            <p id="customer-help" class="mt-1 text-xs text-muted">{{ __('platform::security.filters.customer_help') }}</p></div>
        <div><label for="from" class="block text-sm font-medium">{{ __('platform::security.filters.from') }}</label><input id="from" name="from" type="date" value="{{ $filters['from'] }}" class="mt-1 w-full rounded-md border border-line px-3 py-2"></div>
        <div><label for="to" class="block text-sm font-medium">{{ __('platform::security.filters.to') }}</label><input id="to" name="to" type="date" value="{{ $filters['to'] }}" class="mt-1 w-full rounded-md border border-line px-3 py-2"></div>
        <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-3 xl:col-span-6"><x-platform::form.button>{{ __('platform::security.filter') }}</x-platform::form.button><x-platform::btn variant="secondary" :href="route('admin.security.logins')">{{ __('platform::security.clear') }}</x-platform::btn></div>
    </form>

    @if ($unknownPerson)<x-platform::alert type="warning">{{ __('platform::security.logins.no_such_person') }}</x-platform::alert>@endif

    <div class="overflow-x-auto" tabindex="0" role="region" aria-label="{{ __('platform::security.logins.title') }}">
        <table class="w-full text-sm">
            <caption class="sr-only">{{ __('platform::security.logins.title') }}</caption>
            <thead><tr class="border-b border-line">
                <th scope="col" class="py-2 pe-3 text-start">{{ __('platform::security.columns.time') }}</th>
                <th scope="col" class="py-2 pe-3 text-start">{{ __('platform::security.columns.person') }}</th>
                <th scope="col" class="py-2 pe-3 text-start">{{ __('platform::security.columns.what') }}</th>
                <th scope="col" class="py-2 pe-3 text-start">{{ __('platform::security.columns.how') }}</th>
                <th scope="col" class="py-2 pe-3 text-start">{{ __('platform::security.columns.device') }}</th>
                <th scope="col" class="py-2 text-start">{{ __('platform::security.columns.ip') }}</th>
            </tr></thead>
            <tbody>
            @forelse ($page['rows'] as $r)
                @php($result = AuditCatalog::SIGN_IN[$r->action] ?? 'success')
                @php([$method, $where] = $how($r))
                @php($person = $people[$r->id]['subject'] ?? $people[$r->id]['actor'] ?? null)
                <tr class="border-b border-line align-top">
                    <td class="py-2 pe-3 whitespace-nowrap">{{ Fmt::dateTime(CarbonImmutable::parse($r->created_at, 'UTC')) }}<span class="block text-xs text-muted">{{ Fmt::relative(CarbonImmutable::parse($r->created_at, 'UTC')) }}</span></td>
                    <td class="py-2 pe-3">@include('platform::admin.security._person', ['person' => $person, 'fallback' => __('platform::security.logins.unknown_account')])</td>
                    <td class="py-2 pe-3"><x-platform::badge :tone="$tone[$result]">{{ \App\Modules\Platform\Support\AuditCatalog::loginLabel($r->action) }}</x-platform::badge></td>
                    <td class="py-2 pe-3">@if ($method !== ''){{ __('platform::security.method.'.$method) }}@endif<span class="block text-xs text-muted">{{ __('platform::security.where.'.$where) }}</span></td>
                    <td class="py-2 pe-3">{{ UserAgentLabel::label($r->user_agent) }}</td>
                    <td class="py-2 font-mono">{{ AuditLogQuery::maskIp($r->ip) ?: '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="py-6 text-center text-muted">{{ __('platform::security.logins.empty') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if ($page['next'])<x-platform::btn variant="secondary" :href="route('admin.security.logins', array_filter($filters) + ['before' => $page['next']])">{{ __('platform::security.older') }}</x-platform::btn>@endif
</div>
@endsection
