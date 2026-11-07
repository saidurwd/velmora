@extends('layouts.admin')

@section('title', __('reporting::exports.title'))

@php
    use App\Modules\Platform\Support\Fmt;
@endphp

@section('content')
<div class="max-w-5xl space-y-6">
    <p><a class="text-sm underline" href="{{ route('admin.reports.index') }}">{{ __('reporting::reports.all_reports') }}</a></p>
    <h1 class="text-2xl font-bold">{{ __('reporting::exports.title') }}</h1>
    <p class="text-sm text-muted">{{ __('reporting::exports.intro', ['days' => config('reporting.exports.expires_days')]) }}</p>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    @if ($exports === [])
        <p class="text-muted">{{ __('reporting::exports.none') }}</p>
    @else
    <div class="overflow-x-auto"><table class="w-full text-left text-sm">
        <caption class="sr-only">{{ __('reporting::exports.title') }}</caption>
        <thead><tr class="border-b border-line"><th scope="col" class="py-2 pe-3">{{ __('reporting::exports.report') }}</th><th scope="col" class="py-2 pe-3">{{ __('reporting::exports.asked') }}</th><th scope="col" class="py-2 pe-3">{{ __('reporting::exports.state') }}</th><th scope="col" class="py-2 pe-3 text-end">{{ __('reporting::exports.rows') }}</th><th scope="col" class="py-2 pe-3">{{ __('reporting::exports.file') }}</th></tr></thead>
        <tbody>
        @foreach ($exports as $e)
            <tr class="border-b border-line align-top">
                <th scope="row" class="py-2 pe-3 font-normal">{{ $e['known'] ? __('reporting::catalogue.'.$e['report'].'.title') : $e['report'] }}</th>
                <td class="py-2 pe-3">{{ Fmt::dateTime($e['asked']) }}</td>
                <td class="py-2 pe-3"><x-platform::badge :tone="$e['status'] === 'ready' ? 'success' : ($e['status'] === 'failed' ? 'danger' : 'neutral')">{{ __('reporting::exports.status.'.$e['status']) }}</x-platform::badge>
                    @if ($e['status'] === 'failed')<div class="text-xs text-muted">{{ __('reporting::exports.errors.'.($e['error'] ?? 'failed')) }}</div>@endif</td>
                <td class="py-2 pe-3 text-end">{{ $e['rows'] === null ? '—' : Fmt::number((int) $e['rows']) }}</td>
                <td class="py-2 pe-3">
                    @if ($e['link'])<a class="underline" href="{{ $e['link'] }}">{{ __('reporting::exports.download') }}</a><div class="text-xs text-muted">{{ __('reporting::exports.until', ['time' => Fmt::dateTime($e['expires'])]) }}</div>
                    @elseif ($e['status'] === 'expired')<span class="text-muted">{{ __('reporting::exports.gone') }}</span>@endif
                </td>
            </tr>
        @endforeach
        </tbody></table></div>
        <p class="text-xs text-muted">{{ __('reporting::exports.link_note', ['minutes' => $minutes]) }}</p>
    @endif
</div>
@endsection
