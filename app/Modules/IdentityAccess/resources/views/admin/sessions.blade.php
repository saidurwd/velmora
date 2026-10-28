@extends('layouts.admin')

@section('title', __('identity-access::admin.sessions.title'))

@section('content')
<div class="max-w-3xl space-y-6">
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
                <td class="py-2">{{ \Illuminate\Support\Str::limit((string) $session->user_agent, 60) }} @if ($session->session_id_hash === $currentHash)<strong>({{ __('identity-access::admin.sessions.this_device') }})</strong>@endif</td>
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
</div>
@endsection
