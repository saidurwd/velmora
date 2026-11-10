@extends('layouts.admin')

@section('title', __('platform::ops.integrity.title'))

@section('content')
<div class="max-w-4xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <h1 class="text-2xl font-bold">{{ __('platform::ops.integrity.title') }}</h1>
        <form method="POST" action="{{ route('admin.ops.integrity.run') }}">@csrf<button class="min-h-11 rounded-md bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-700">{{ __('platform::ops.integrity.run_all') }}</button></form>
    </div>
    <p class="text-sm text-muted">{{ __('platform::ops.integrity.intro') }}</p>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    <x-platform::form.errors />

    <ul class="space-y-3">
        @foreach ($rows as $r)
            <li class="space-y-2 rounded-md border border-line p-4">
                <div class="flex flex-wrap items-center gap-3">
                    <span class="font-medium">{{ trans()->has('platform::ops.checks.'.$r['code']) ? __('platform::ops.checks.'.$r['code']) : $r['code'] }}</span>
                    <span class="rounded px-2 py-0.5 text-xs {{ $r['status'] === 'ok' ? 'bg-green-100 text-success' : ($r['status'] === null ? 'bg-surface-alt' : 'bg-red-100 text-danger') }}">{{ $r['status'] ? __('platform::ops.integrity.statuses.'.$r['status']) : __('platform::ops.integrity.not_run') }}</span>
                    @if ($r['ran'])<span class="text-sm text-muted">{{ \App\Modules\Platform\Support\Fmt::dateTime($r['ran']) }} · {{ __('platform::ops.milliseconds', ['n' => $r['ms']]) }}</span>@endif
                    <form method="POST" action="{{ route('admin.ops.integrity.run') }}" class="ms-auto">@csrf<input type="hidden" name="code" value="{{ $r['code'] }}"><button class="underline">{{ __('platform::ops.integrity.run_one') }}</button></form>
                </div>
                @if ($r['status'] === 'problem')<p class="text-danger">{{ __('platform::ops.integrity.problems', ['count' => $r['problems']]) }}</p>@endif
                @if ($r['note'])<p class="text-sm text-muted">{{ $r['note'] }}</p>@endif
                @if ($r['samples'] !== [])<ul class="list-disc ps-6 text-sm">@foreach ($r['samples'] as $s)<li class="font-mono">{{ $s }}</li>@endforeach</ul>@endif
            </li>
        @endforeach
    </ul>
</div>
@endsection
