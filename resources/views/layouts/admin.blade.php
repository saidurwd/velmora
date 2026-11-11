@extends('layouts.base')

@section('body')
    @php $user = auth('admin')->user(); @endphp
    <div class="flex min-h-screen">
        <nav class="w-60 shrink-0 space-y-6 border-e border-line bg-surface-alt p-4" aria-label="{{ __('layout.admin_navigation') }}">
            <p class="font-bold text-brand-700">{{ config('app.name') }}</p>
            @if ($user && Route::has('admin.search'))
                <form method="GET" action="{{ route('admin.search') }}" role="search">
                    <label for="admin-search" class="sr-only">{{ __('platform::productivity.search.label') }}</label>
                    <input id="admin-search" name="q" type="search" maxlength="60" value="{{ request()->routeIs('admin.search') ? request('q') : '' }}" placeholder="{{ __('platform::productivity.search.go') }}" class="block w-full rounded-md border border-line px-3 py-2 text-sm">
                </form>
            @endif
            <ul class="space-y-1">
                @foreach (app(\App\Modules\Platform\Support\AdminMenu::class)->visibleFor($user) as $item)
                    <li>
                        @php $active = request()->routeIs($item['route'].'*') && collect($item['params'])->every(fn ($v, $k) => request()->route($k) === $v); @endphp
                        <a href="{{ route($item['route'], $item['params']) }}" @if ($active) aria-current="page" @endif
                           class="block rounded-md px-3 py-2 hover:bg-line {{ $active ? 'bg-line font-semibold' : '' }}">{{ __($item['label']) }}</a>
                    </li>
                @endforeach
            </ul>
            <x-platform::language-switcher />
            @if ($user)
                <form method="POST" action="{{ route('admin.logout') }}">
                    @csrf
                    <button class="text-sm underline">{{ __('layout.sign_out') }} ({{ $user->name }})</button>
                </form>
            @endif
        </nav>
        <main id="main" class="flex-1 p-6" tabindex="-1">
            @yield('content')
        </main>
    </div>
@endsection
