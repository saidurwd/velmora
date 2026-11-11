@extends('layouts.admin')

@section('title', __('platform::admin.dashboard'))

@section('content')
    <h1 class="text-2xl font-bold">{{ __('platform::admin.dashboard') }}</h1>
    @if (session('status'))<x-platform::alert type="info" class="mt-4">{{ session('status') }}</x-platform::alert>@endif

    @if ($tiles === [])
        <p class="mt-2 text-muted">{{ __('platform::admin.welcome') }}</p>
    @else
        <ul class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3" aria-label="{{ __('platform::productivity.dashboard.tiles') }}">
            @foreach ($tiles as $tile)
                @php($f = $tile['figure'])
                <li class="rounded-lg border border-line p-4">
                    <p class="text-sm text-muted">{{ __($tile['label']) }}</p>
                    @if ($f === null)
                        <p class="mt-1 text-muted">{{ __('platform::productivity.dashboard.unavailable') }}</p>
                    @else
                        <a href="{{ route($f->route, $f->params) }}" class="mt-1 block text-3xl font-bold hover:underline">
                            @if ($f->kind === 'money'){{ \App\Modules\Platform\Support\Fmt::money(\App\Modules\Platform\DTOs\Money::of($f->value)) }}@else{{ \App\Modules\Platform\Support\Fmt::number($f->value) }}@if ($f->capped)+@endif @endif
                            <span class="sr-only">— {{ __('platform::productivity.dashboard.open_list') }}</span>
                        </a>
                        @if ($f->before !== null)
                            <p class="mt-1 text-sm text-muted">{{ __('platform::productivity.dashboard.yesterday') }}: @if ($f->kind === 'money'){{ \App\Modules\Platform\Support\Fmt::money(\App\Modules\Platform\DTOs\Money::of($f->before)) }}@else{{ \App\Modules\Platform\Support\Fmt::number($f->before) }}@endif</p>
                        @endif
                        @if ($f->series !== [])
                            @php($max = max(1, max($f->series)))
                            <div class="mt-2 flex h-10 items-end gap-1" aria-hidden="true">
                                @foreach ($f->series as $v)<span class="w-full rounded-sm bg-brand-700/70" style="height: {{ max(4, (int) round($v / $max * 100)) }}%"></span>@endforeach
                            </div>
                            <p class="sr-only">{{ __('platform::productivity.dashboard.last_days', ['n' => count($f->series)]) }}: {{ implode(', ', $f->series) }}</p>
                        @endif
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
@endsection
