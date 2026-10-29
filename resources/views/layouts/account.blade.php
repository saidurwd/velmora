@extends('layouts.storefront')

@section('content')
<div class="grid gap-8 md:grid-cols-[14rem_1fr]">
    <nav aria-label="{{ __('layout.account_navigation') }}">
        <ul class="space-y-1">
            @foreach ([['account', 'layout.account_overview'], ['account.profile', 'layout.account_profile'], ['account.addresses', 'layout.account_addresses'], ['account.security', 'layout.account_security']] as [$route, $label])
                <li>
                    <a href="{{ route($route) }}" @if (request()->routeIs($route)) aria-current="page" @endif
                       class="block rounded-md px-3 py-2 hover:bg-surface-alt {{ request()->routeIs($route) ? 'bg-surface-alt font-semibold' : '' }}">{{ __($label) }}</a>
                </li>
            @endforeach
        </ul>
    </nav>
    <div class="space-y-6">
        @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
        <x-platform::form.errors />
        @yield('account')
    </div>
</div>
@endsection
