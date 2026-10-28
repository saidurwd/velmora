@extends('layouts.account')

@section('title', __('identity-access::account.security.title'))

@section('account')
<div class="max-w-xl space-y-10">
    <h1 class="text-2xl font-bold">{{ __('identity-access::account.security.title') }}</h1>

    <section class="space-y-4" aria-labelledby="pw">
        <h2 id="pw" class="text-lg font-semibold">{{ $hasPassword ? __('identity-access::account.password.title') : __('identity-access::account.password.set_title') }}</h2>
        <form method="POST" action="{{ route('account.password.update') }}" class="space-y-4" novalidate>
            @csrf @method('PUT')
            @if ($hasPassword)
                <x-platform::form.field name="current_password" type="password" :label="__('identity-access::account.password.current')" autocomplete="current-password" required />
            @endif
            <x-platform::form.field name="password" type="password" :label="__('identity-access::account.password.new')" :help="__('identity-access::auth.register.password_help')" autocomplete="new-password" required />
            <x-platform::form.field name="password_confirmation" type="password" :label="__('identity-access::auth.reset.confirm')" autocomplete="new-password" required />
            <x-platform::form.button>{{ __('identity-access::account.password.submit') }}</x-platform::form.button>
        </form>
    </section>

    <section class="space-y-4" aria-labelledby="em">
        <h2 id="em" class="text-lg font-semibold">{{ __('identity-access::account.email.title') }}</h2>
        <p class="text-sm text-muted">{{ __('identity-access::account.email.current', ['email' => $user->email ?? __('identity-access::account.none')]) }}</p>
        <form method="POST" action="{{ route('account.email.request') }}" class="space-y-4" novalidate>
            @csrf
            <x-platform::form.field name="email" type="email" :label="__('identity-access::account.email.new')" :help="__('identity-access::account.email.help')" autocomplete="email" required />
            <x-platform::form.button variant="secondary">{{ __('identity-access::account.email.submit') }}</x-platform::form.button>
        </form>
    </section>

    <section class="space-y-4" aria-labelledby="ph">
        <h2 id="ph" class="text-lg font-semibold">{{ __('identity-access::account.phone.title') }}</h2>
        <p class="text-sm text-muted">{{ __('identity-access::account.phone.current', ['phone' => $user->phone ? (\App\Modules\Platform\DTOs\PhoneNumber::tryParse($user->phone)?->masked() ?? '') : __('identity-access::account.none')]) }}</p>
        <form method="POST" action="{{ route('account.phone.request') }}" class="space-y-4" novalidate>
            @csrf
            <x-platform::form.field name="phone" type="tel" inputmode="tel" :label="__('identity-access::account.phone.new')" :help="__('identity-access::auth.phone.help')" autocomplete="tel" required />
            <x-platform::form.button variant="secondary">{{ __('identity-access::account.phone.send') }}</x-platform::form.button>
        </form>
        @if ($pendingPhone)
            <form method="POST" action="{{ route('account.phone.confirm') }}" class="space-y-4" novalidate>
                @csrf
                <x-platform::form.field name="code" inputmode="numeric" autocomplete="one-time-code" :label="__('identity-access::auth.phone.code_label')" required />
                <x-platform::form.button>{{ __('identity-access::account.phone.confirm') }}</x-platform::form.button>
            </form>
        @endif
    </section>

    <section class="space-y-4" aria-labelledby="dv">
        <h2 id="dv" class="text-lg font-semibold">{{ __('identity-access::account.sessions.title') }}</h2>
        <p class="text-sm text-muted">{{ __('identity-access::account.sessions.count', ['count' => $devices]) }}</p>
        <form method="POST" action="{{ route('account.sessions.revoke') }}">
            @csrf
            <x-platform::form.button variant="secondary">{{ __('identity-access::account.sessions.revoke_others') }}</x-platform::form.button>
        </form>
    </section>

    <section class="space-y-4 border-t border-line pt-6" aria-labelledby="del">
        <h2 id="del" class="text-lg font-semibold">{{ __('identity-access::account.delete.title') }}</h2>
        <p class="text-sm text-muted">{{ __('identity-access::account.delete.explain') }}</p>
        <form method="POST" action="{{ route('account.delete') }}">
            @csrf
            <x-platform::form.button variant="secondary">{{ __('identity-access::account.delete.submit') }}</x-platform::form.button>
        </form>
    </section>
</div>
@endsection
