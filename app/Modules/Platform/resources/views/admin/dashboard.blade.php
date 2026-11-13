@extends('layouts.admin')

@section('title', __('platform::admin.dashboard'))

@php
    use App\Modules\Platform\DTOs\Money;
    use App\Modules\Platform\Support\Fmt;
    $show = fn ($f) => $f->kind === 'money' ? Fmt::money(Money::of($f->value)) : Fmt::number($f->value).($f->capped ? '+' : '');
@endphp

@section('content')
<div class="mx-auto max-w-7xl space-y-8">
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold">{{ __('platform::productivity.dashboard.greeting_'.$greetingPart, ['name' => $firstName]) }}</h1>
            <p class="mt-1 text-sm text-muted">{{ Fmt::date($now, 'long') }}</p>
        </div>
        @if ($shortcuts !== [])
            <nav aria-label="{{ __('platform::productivity.dashboard.shortcuts') }}">
                <ul class="flex flex-wrap gap-2">
                    @foreach ($shortcuts as [$label, $route])
                        <li><a href="{{ route($route) }}" class="inline-flex min-h-11 items-center rounded-md border border-line bg-surface px-4 py-2 text-sm font-medium hover:bg-line">{{ __($label) }}</a></li>
                    @endforeach
                </ul>
            </nav>
        @endif
    </header>

    @if (session('status'))<x-platform::alert type="info">{{ session('status') }}</x-platform::alert>@endif

    @if ($today === [] && $attention === [])
        <p class="text-muted">{{ __('platform::admin.welcome') }}</p>
    @endif

    @if ($today !== [])
        <section aria-labelledby="dash-today" class="space-y-3">
            <h2 id="dash-today" class="text-lg font-semibold">{{ __('platform::productivity.dashboard.today') }}</h2>
            <ul class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($today as $card)
                    @php $f = $card['figure']; $c = $card['change']; @endphp
                    <li class="flex flex-col rounded-lg border border-line bg-surface p-5">
                        <h3 class="text-sm font-medium text-muted">{{ __($card['label']) }}</h3>
                        @if ($f === null)
                            <p class="mt-2 text-muted">{{ __('platform::productivity.dashboard.unavailable') }}</p>
                        @else
                            <p class="mt-2 text-3xl font-bold tracking-tight">{{ $show($f) }}</p>
                            @if ($c !== null)
                                <p class="mt-1 flex items-center gap-1 text-sm text-muted">
                                    @if ($c['direction'] === 'up')
                                        <svg class="size-4 shrink-0" aria-hidden="true" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 5-5 5 5"/></svg>
                                    @elseif ($c['direction'] === 'down')
                                        <svg class="size-4 shrink-0" aria-hidden="true" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m5 8 5 5 5-5"/></svg>
                                    @else
                                        <svg class="size-4 shrink-0" aria-hidden="true" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M5 10h10"/></svg>
                                    @endif
                                    <span>
                                        @if ($c['direction'] === 'same')
                                            {{ __('platform::productivity.dashboard.same_as_yesterday') }}
                                        @elseif ($c['percent'] < 0)
                                            {{ __('platform::productivity.dashboard.new_since_yesterday', ['before' => $show(new \App\Modules\Platform\DTOs\TileFigure($f->before, $f->kind, $f->route))]) }}
                                        @else
                                            {{ __('platform::productivity.dashboard.'.$c['direction'].'_from_yesterday', ['percent' => Fmt::number($c['percent'])]) }}
                                        @endif
                                        <span class="text-muted">· {{ __('platform::productivity.dashboard.yesterday') }}: {{ $show(new \App\Modules\Platform\DTOs\TileFigure($f->before, $f->kind, $f->route)) }}</span>
                                    </span>
                                </p>
                            @endif
                            @if ($f->series !== [])
                                @php $max = max(1, max($f->series)); @endphp
                                <div class="mt-4 flex h-12 items-end gap-1" aria-hidden="true">
                                    @foreach ($f->series as $i => $v)<span class="w-full rounded-sm {{ $loop->last ? 'bg-brand-700' : 'bg-brand-700/40' }}" style="height: {{ max(4, (int) round($v / $max * 100)) }}%"></span>@endforeach
                                </div>
                                <p class="sr-only">{{ __('platform::productivity.dashboard.last_days', ['n' => count($f->series)]) }}: {{ implode(', ', $f->series) }}</p>
                            @endif
                            <p class="mt-4 text-sm"><a href="{{ route($f->route, $f->params) }}" class="font-medium text-brand-700 underline">{{ __('platform::productivity.dashboard.view') }}<span class="sr-only"> — {{ __($card['label']) }}</span></a></p>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($attention !== [])
        <section aria-labelledby="dash-attention" class="space-y-3">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="dash-attention" class="text-lg font-semibold">{{ __('platform::productivity.dashboard.tiles') }}</h2>
                <p class="text-sm text-muted">{{ $attentionTotal > 0 ? __('platform::productivity.dashboard.items_waiting', ['n' => Fmt::number($attentionTotal)]) : __('platform::productivity.dashboard.nothing_waiting') }}</p>
            </div>
            <ul class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($attention as $card)
                    @php $f = $card['figure']; $waiting = $f !== null && $f->kind === 'count' && $f->value > 0; @endphp
                    <li class="flex items-start justify-between gap-4 rounded-lg border bg-surface p-5 {{ $waiting ? 'border-s-4 border-line border-s-brand-700' : 'border-line' }}">
                        <div class="min-w-0">
                            <h3 class="text-sm font-medium {{ $waiting ? '' : 'text-muted' }}">{{ __($card['label']) }}</h3>
                            @if ($f === null)
                                <p class="mt-2 text-muted">{{ __('platform::productivity.dashboard.unavailable') }}</p>
                            @elseif ($waiting || $f->kind === 'money')
                                <p class="mt-2 text-3xl font-bold tracking-tight">{{ $show($f) }}</p>
                            @else
                                <p class="mt-2 flex items-center gap-2 text-sm text-muted">
                                    <svg class="size-5 shrink-0" aria-hidden="true" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="10" cy="10" r="8"/><path d="m6.5 10.5 2.5 2.5 4.5-5"/></svg>
                                    {{ __('platform::productivity.dashboard.all_clear') }}
                                </p>
                            @endif
                        </div>
                        @if ($f !== null)
                            <a href="{{ route($f->route, $f->params) }}" class="shrink-0 self-center text-sm font-medium text-brand-700 underline">{{ __('platform::productivity.dashboard.view') }}<span class="sr-only"> — {{ __($card['label']) }}</span></a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
@endsection
