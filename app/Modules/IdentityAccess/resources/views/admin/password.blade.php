@extends('layouts.admin')

@section('title', __('identity-access::admin.password.title'))

@section('content')
<div class="max-w-2xl space-y-6">
    <div>
        <h1 class="text-2xl font-bold">{{ __('identity-access::admin.password.title') }}</h1>
        <p class="mt-1 text-sm text-muted">{{ __('identity-access::admin.password.intro') }}</p>
    </div>

    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    <x-platform::form.errors />

    <form method="POST" action="{{ route('admin.account.password.update') }}" class="space-y-5 rounded-lg border border-line p-6" novalidate>
        @csrf
        @method('PUT')
        <x-platform::form.field name="current_password" type="password" :label="__('identity-access::admin.password.current')" autocomplete="current-password" required />
        <x-platform::form.field name="password" type="password" :label="__('identity-access::admin.password.new')" autocomplete="new-password" :help="__('identity-access::auth.register.password_help')" required />
        <x-platform::form.field name="password_confirmation" type="password" :label="__('identity-access::admin.password.confirm')" autocomplete="new-password" required />
        <x-platform::alert type="info">{{ __('identity-access::admin.password.after') }}</x-platform::alert>
        <div class="flex flex-wrap items-center gap-3">
            <x-platform::form.button>{{ __('identity-access::admin.password.save') }}</x-platform::form.button>
            <a class="text-sm underline" href="{{ route('admin.sessions') }}">{{ __('identity-access::admin.menu.sessions') }}</a>
        </div>
    </form>
</div>
@endsection
