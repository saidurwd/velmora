@extends('layouts.storefront')

@section('content')
<div class="grid gap-8 md:grid-cols-[14rem_1fr]">
    <nav aria-label="{{ __('layout.account_navigation') }}">
        <ul class="space-y-1">
            @foreach ([['account', 'layout.account_overview'], ['account.profile', 'layout.account_profile'], ['account.orders', 'layout.account_orders'], ['digital.library', 'layout.account_library'], ['bookings.mine', 'layout.account_bookings'], ['account.wishlist', 'layout.account_wishlist'], ['account.reviews', 'layout.account_reviews'], ['account.returns', 'layout.account_returns'], ['account.support', 'layout.account_support'], ['account.addresses', 'layout.account_addresses'], ['account.security', 'layout.account_security'], ['account.preferences', 'layout.account_preferences'], ['account.privacy', 'layout.account_privacy'], ['account.policies', 'layout.account_policies']] as [$route, $label])
                <li>
                    <a href="{{ route($route) }}" @if (request()->routeIs($route)) aria-current="page" @endif
                       class="block rounded-md px-3 py-2 hover:bg-surface-alt {{ request()->routeIs($route) ? 'bg-surface-alt font-semibold' : '' }}">{{ __($label) }}</a>
                </li>
            @endforeach
        </ul>
    </nav>
    <div class="space-y-6">
        @php($policyNotices = request()->routeIs('account.policies') ? [] : app(\App\Modules\Cms\Contracts\PolicyAcceptances::class)->outstanding((int) auth('web')->id(), 'signup', null, app()->getLocale()))
        @if ($policyNotices !== [])
            <x-platform::alert type="info">{{ __('cms::policies.account.banner') }} <a class="underline" href="{{ route('account.policies') }}">{{ __('cms::policies.account.review') }}</a></x-platform::alert>
        @endif
        @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
        <x-platform::form.errors />
        @yield('account')
    </div>
</div>
@endsection
