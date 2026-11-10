@extends('layouts.admin')

@section('title', __('platform::ops.title'))

@section('content')
<div class="max-w-3xl space-y-6">
    <h1 class="text-2xl font-bold">{{ __('platform::ops.title') }}</h1>
    <p class="text-muted">{{ __('platform::ops.intro') }}</p>
    <ul class="grid gap-3 sm:grid-cols-2">
        @foreach ([['admin.ops.health', 'health', 'system.view'], ['admin.ops.scheduler', 'scheduler', 'system.view'], ['admin.ops.failed', 'failed', 'queues.view'], ['admin.ops.integrity', 'integrity', 'system.view']] as [$route, $key, $permission])
            @can($permission)
                <li><a href="{{ route($route) }}" class="block rounded-md border border-line p-4 hover:bg-surface-alt"><span class="font-medium">{{ __('platform::ops.'.$key.'.title') }}</span><span class="mt-1 block text-sm text-muted">{{ __('platform::ops.'.$key.'.blurb') }}</span></a></li>
            @endcan
        @endforeach
    </ul>
</div>
@endsection
