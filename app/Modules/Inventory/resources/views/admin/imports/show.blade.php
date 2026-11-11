@extends('layouts.admin')

@section('title', __('inventory::imports.title'))

@section('content')
@php
    use App\Modules\Platform\Support\Fmt;
    use Carbon\CarbonImmutable;
    $same = (int) $import->unchanged_count;
@endphp
<div class="space-y-6">
    <p><a class="underline" href="{{ route('admin.inventory.imports.index') }}">{{ __('inventory::imports.back') }}</a></p>
    <h1 class="text-2xl font-bold">{{ $import->file_name ?: strtoupper(substr($import->public_id, -8)) }}</h1>
    <p class="text-muted">{{ $place }} · {{ __('inventory::imports.mode_name.'.$import->mode) }} · {{ __('inventory::imports.status.'.$import->status) }} · “{{ $import->note }}”</p>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    @error('import')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror
    <p>{{ __('inventory::imports.summary', ['ok' => Fmt::number($summary['ok']), 'same' => Fmt::number($same), 'bad' => Fmt::number($import->error_count)]) }}</p>

    @if ($import->status === 'previewed')
        <x-platform::alert type="info">{{ __('inventory::imports.dry_run') }} {{ __('inventory::imports.expires', ['when' => Fmt::dateTime($expires)]) }}</x-platform::alert>
        @if ($import->error_count > 0)
            <x-platform::alert type="warning">{{ __('inventory::imports.has_errors') }}</x-platform::alert>
            <p><a class="underline" href="{{ route('admin.inventory.imports.errors', $import->public_id) }}">{{ __('inventory::imports.problems_file') }}</a></p>
        @else
            <form method="POST" action="{{ route('admin.inventory.imports.apply', $import->public_id) }}" onsubmit="return confirm(@js(__('inventory::imports.apply_confirm', ['ok' => Fmt::number($summary['ok'])])))">@csrf
                <x-platform::btn type="submit">{{ __('inventory::imports.apply') }}</x-platform::btn>
            </form>
        @endif
    @elseif ($import->status === 'applied')
        <p class="text-sm text-muted">{{ __('inventory::imports.applied_by', ['when' => Fmt::dateTime(CarbonImmutable::parse($import->applied_at, 'UTC'))]) }}</p>
        <form method="POST" action="{{ route('admin.inventory.imports.rollback', $import->public_id) }}" class="space-y-2" onsubmit="return confirm(@js(__('inventory::imports.rollback_confirm')))">@csrf
            <p class="max-w-3xl text-sm text-muted">{{ __('inventory::imports.rollback_help') }}</p>
            <x-platform::btn type="submit" variant="secondary">{{ __('inventory::imports.rollback') }}</x-platform::btn>
        </form>
    @else
        <p class="text-sm text-muted">{{ __('inventory::imports.rolled_by', ['when' => Fmt::dateTime(CarbonImmutable::parse($import->rolled_back_at, 'UTC'))]) }} — {{ __('inventory::imports.rolled_back', ['reverted' => Fmt::number($summary['reverted']), 'kept' => Fmt::number($summary['kept'])]) }}</p>
    @endif

    @if ($import->error_count > 0)
        <p>@if ($onlyErrors)<a class="underline" href="{{ route('admin.inventory.imports.show', $import->public_id) }}">{{ __('inventory::imports.show_all') }}</a>@else<a class="underline" href="{{ route('admin.inventory.imports.show', [$import->public_id, 'only' => 'errors']) }}">{{ __('inventory::imports.only_problems') }}</a>@endif</p>
    @endif
    <div class="overflow-x-auto"><table class="w-full text-sm">
        <caption class="sr-only">{{ $import->file_name }}</caption>
        <thead><tr class="border-b border-line">@foreach (['line', 'sku', 'asked', 'before', 'change', 'after', 'result'] as $col)<th scope="col" class="py-2 pe-3 text-start">{{ __('inventory::imports.row_cols.'.$col) }}</th>@endforeach</tr></thead>
        <tbody>
        @foreach ($lines as $r)
            <tr class="border-b border-line align-top">
                <td class="py-2 pe-3">{{ Fmt::number($r->line) }}</td>
                <td class="py-2 pe-3">{{ $r->sku }}</td>
                <td class="py-2 pe-3">{{ $r->raw_quantity }}</td>
                <td class="py-2 pe-3">{{ $r->before_on_hand === null ? '—' : Fmt::number($r->before_on_hand) }}</td>
                <td class="py-2 pe-3">{{ ($r->applied_delta ?? $r->delta) === null ? '—' : Fmt::number($r->applied_delta ?? $r->delta) }}</td>
                <td class="py-2 pe-3">{{ $r->after_on_hand === null ? '—' : Fmt::number($r->after_on_hand) }}</td>
                <td class="py-2 pe-3">
                    @if ($r->status === 'error')<x-platform::badge tone="danger">{{ __('inventory::imports.row_status.error') }}</x-platform::badge> {{ __('inventory::ops.errors.row_'.$r->error_code) }}
                    @else{{ __('inventory::imports.row_status.'.$r->status) }}@if ($r->rollback_state) — {{ __('inventory::imports.rollback_state.'.$r->rollback_state) }}@endif
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    {{ $lines->links() }}
</div>
@endsection
