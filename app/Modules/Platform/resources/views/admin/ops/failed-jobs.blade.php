@extends('layouts.admin')

@section('title', __('platform::ops.failed.title'))

@section('content')
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <h1 class="text-2xl font-bold">{{ __('platform::ops.failed.title') }}</h1>
        <a class="underline" href="{{ url('/horizon') }}" rel="noopener">{{ __('platform::ops.failed.horizon') }}</a>
    </div>
    <p class="text-sm text-muted">{{ __('platform::ops.failed.intro') }}</p>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    <x-platform::form.errors />

    <table class="w-full text-sm">
        <caption class="sr-only">{{ __('platform::ops.failed.title') }}</caption>
        <thead><tr class="border-b border-line">
            <th scope="col" class="py-2 text-start">{{ __('platform::ops.failed.columns.job') }}</th>
            <th scope="col" class="py-2 text-start">{{ __('platform::ops.failed.columns.queue') }}</th>
            <th scope="col" class="py-2 text-start">{{ __('platform::ops.failed.columns.failed_at') }}</th>
            <th scope="col" class="py-2 text-start">{{ __('platform::ops.failed.columns.error') }}</th>
            <th scope="col" class="py-2 text-start">{{ __('platform::ops.failed.columns.actions') }}</th>
        </tr></thead>
        <tbody>
        @forelse ($page['rows'] as $r)
            <tr class="border-b border-line align-top">
                <td class="py-2 font-mono">{{ $r['class'] }}</td>
                <td class="py-2">{{ $r['queue'] }}</td>
                <td class="py-2">{{ \App\Modules\Platform\Support\Fmt::dateTime($r['failed_at']) }}</td>
                <td class="py-2 break-words">{{ $r['error'] }}</td>
                <td class="space-y-1 py-2">
                    @can('queues.manage')
                        @if ($r['retryable'])
                            <form method="POST" action="{{ route('admin.ops.failed.retry', $r['id']) }}">@csrf<button class="min-h-11 rounded-md border border-line px-3 py-2">{{ __('platform::ops.failed.retry') }}</button></form>
                        @else
                            <span class="text-xs text-muted">{{ __('platform::ops.failed.not_retryable') }}</span>
                        @endif
                        <form method="POST" action="{{ route('admin.ops.failed.forget', $r['id']) }}" data-confirm="{{ __('platform::ops.failed.confirm_forget') }}">@csrf<button class="underline text-danger">{{ __('platform::ops.failed.forget') }}</button></form>
                    @endcan
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="py-6 text-center text-muted">{{ __('platform::ops.failed.empty') }}</td></tr>
        @endforelse
        </tbody>
    </table>
    @if ($page['next'])<a class="underline" href="{{ route('admin.ops.failed', ['before' => $page['next']]) }}">{{ __('platform::ops.failed.older') }}</a>@endif
</div>
@endsection
