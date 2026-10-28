@extends('layouts.admin')

@section('title', __('search::admin.zero'))

@section('content')
<div class="max-w-3xl space-y-6">
    <h1 class="text-2xl font-bold">{{ __('search::admin.zero') }}</h1>
    <p class="text-muted">{{ __('search::admin.zero_help') }}</p>

    <nav aria-label="{{ __('search::admin.period') }}" class="flex gap-2">
        @foreach ([7, 30, 90] as $d)
            <a href="{{ route('admin.search.zero.index', ['days' => $d]) }}" @if ($d === $days) aria-current="true" @endif class="inline-flex min-h-11 items-center rounded-md border px-4 {{ $d === $days ? 'border-brand-600 bg-brand-50 font-semibold' : 'border-line' }}">{{ __('search::admin.days', ['n' => \App\Modules\Platform\Support\Fmt::number($d)]) }}</a>
        @endforeach
    </nav>
    <p>{{ __('search::admin.total_searches', ['n' => \App\Modules\Platform\Support\Fmt::number($total)]) }}</p>

    <table class="w-full text-sm">
        <caption class="sr-only">{{ __('search::admin.zero') }}</caption>
        <thead><tr class="border-b border-line">
            <th scope="col" class="py-2 text-start">{{ __('search::admin.query') }}</th>
            <th scope="col" class="py-2 text-start">{{ __('search::admin.language') }}</th>
            <th scope="col" class="py-2 text-end">{{ __('search::admin.times') }}</th>
            <th scope="col" class="py-2 text-start">{{ __('search::admin.last') }}</th>
        </tr></thead>
        <tbody>
        @forelse ($rows as $r)
            <tr class="border-b border-line">
                <td class="py-2" lang="{{ $r->locale }}">{{ $r->query }}</td><td class="py-2">{{ strtoupper($r->locale) }}</td>
                <td class="py-2 text-end">{{ \App\Modules\Platform\Support\Fmt::number($r->searches) }}</td>
                <td class="py-2">{{ \App\Modules\Platform\Support\Fmt::dateTime(\Carbon\CarbonImmutable::parse($r->last_at, 'UTC')) }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="py-6 text-center text-muted">{{ __('search::admin.empty') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
