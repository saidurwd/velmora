@extends('layouts.admin')

@section('title', $import->filename)

@php
    $failure = $import->failure;
    $ignored = str_starts_with((string) $failure, 'ignored:') ? explode(',', substr($failure, 8)) : [];
    $failKey = $import->status === 'failed' ? explode(':', (string) $failure, 2) : null;
    $failParams = $failKey && isset($failKey[1]) ? (json_decode($failKey[1], true) ?: []) : [];
@endphp

@section('content')
<div class="max-w-4xl space-y-6">
    <h1 class="text-2xl font-bold">{{ $import->filename }}</h1>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif

    <p>{{ $import->dry_run ? __('catalog::admin.import.mode_check') : __('catalog::admin.import.mode_apply') }} · {{ __('catalog::admin.import.statuses.'.$import->status) }}</p>

    @if (in_array($import->status, ['queued', 'running'], true))
        <p class="text-muted">{{ __('catalog::admin.import.working') }}</p>
        <a class="underline" href="{{ route('admin.catalog.import.show', $import) }}">{{ __('catalog::admin.import.refresh') }}</a>
    @elseif ($import->status === 'failed')
        <x-platform::alert type="error">{{ __('catalog::admin.import.failures.'.$failKey[0], $failParams) }}</x-platform::alert>
    @else
        <dl class="grid grid-cols-2 gap-2 sm:grid-cols-4">
            <div><dt class="text-sm text-muted">{{ __('catalog::admin.import.rows') }}</dt><dd class="text-xl font-semibold">{{ \App\Modules\Platform\Support\Fmt::number($import->total_rows) }}</dd></div>
            <div><dt class="text-sm text-muted">{{ $import->dry_run ? __('catalog::admin.import.would_create') : __('catalog::admin.import.created') }}</dt><dd class="text-xl font-semibold">{{ \App\Modules\Platform\Support\Fmt::number($import->created_count) }}</dd></div>
            <div><dt class="text-sm text-muted">{{ $import->dry_run ? __('catalog::admin.import.would_update') : __('catalog::admin.import.updated') }}</dt><dd class="text-xl font-semibold">{{ \App\Modules\Platform\Support\Fmt::number($import->updated_count) }}</dd></div>
            <div><dt class="text-sm text-muted">{{ __('catalog::admin.import.errors') }}</dt><dd class="text-xl font-semibold">{{ \App\Modules\Platform\Support\Fmt::number($import->error_count) }}</dd></div>
        </dl>

        @if ($ignored !== [])<p class="text-muted">{{ __('catalog::admin.import.ignored_columns', ['columns' => implode(', ', $ignored)]) }}</p>@endif

        @if ($import->dry_run && ($import->created_count + $import->updated_count) > 0)
            @can('catalog.create')
                <form method="POST" action="{{ route('admin.catalog.import.apply', $import) }}" class="space-y-2">
                    @csrf
                    @if ($import->error_count > 0)<p class="text-warning">{{ __('catalog::admin.import.apply_skips_errors', ['count' => $import->error_count]) }}</p>@endif
                    <x-platform::form.button>{{ __('catalog::admin.import.apply') }}</x-platform::form.button>
                </form>
            @endcan
        @endif

        @if (collect($import->results)->contains(fn ($r) => $r['outcome'] === 'error'))
            <table class="w-full text-sm">
                <caption class="sr-only">{{ __('catalog::admin.import.errors') }}</caption>
                <thead><tr class="border-b border-line">
                    <th scope="col" class="py-2 text-start">{{ __('catalog::admin.import.line') }}</th>
                    <th scope="col" class="py-2 text-start">{{ __('catalog::admin.variants.sku') }}</th>
                    <th scope="col" class="py-2 text-start">{{ __('catalog::admin.import.problem') }}</th>
                </tr></thead>
                <tbody>
                @foreach ($import->results as $row)
                    @continue($row['outcome'] !== 'error')
                    <tr class="border-b border-line align-top">
                        <td class="py-2">{{ \App\Modules\Platform\Support\Fmt::number($row['line']) }}</td>
                        <td class="py-2">{{ $row['sku'] }}</td>
                        <td class="py-2">
                            @foreach ($row['errors'] as $e)<p>{{ $e['key'] === 'domain' ? $e['params']['message'] : __('catalog::admin.import.errs.'.$e['key'], $e['params']) }}</p>@endforeach
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    @endif
</div>
@endsection
