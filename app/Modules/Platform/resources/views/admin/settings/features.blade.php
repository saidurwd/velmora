@extends('layouts.admin')

@section('title', __('platform::settings.features.title'))

@section('content')
<div class="max-w-3xl space-y-6">
    <h1 class="text-2xl font-bold">{{ __('platform::settings.features.title') }}</h1>
    <p class="text-muted">{{ __('platform::settings.features.intro') }}</p>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif

    <ul class="space-y-3">
        @foreach ($flags as $flag)
            <li class="space-y-2 rounded-md border border-line p-4">
                <div class="flex flex-wrap items-center gap-3">
                    <span class="font-medium">{{ __('platform::settings.features.names.'.$flag['name']) }}</span>
                    <span class="rounded px-2 py-0.5 text-xs {{ $flag['on'] ? 'bg-green-100 text-success' : 'bg-surface-alt' }}">{{ $flag['on'] ? __('platform::settings.features.on') : __('platform::settings.features.off') }}</span>
                    <span class="text-sm text-muted">{{ $flag['byStaff'] ? __('platform::settings.features.set_by_staff') : __('platform::settings.features.follows_default', ['default' => $flag['default'] ? __('platform::settings.features.on') : __('platform::settings.features.off')]) }}</span>
                </div>
                <p class="text-sm text-muted">{{ __('platform::settings.features.help.'.$flag['name']) }}</p>
                @can('features.manage')
                    <form method="POST" action="{{ route('admin.features.update', $flag['name']) }}" class="flex flex-wrap gap-2">
                        @csrf @method('PUT')
                        <button name="value" value="on" class="min-h-11 rounded-md border border-line px-4 py-2">{{ __('platform::settings.features.turn_on') }}</button>
                        <button name="value" value="off" class="min-h-11 rounded-md border border-line px-4 py-2">{{ __('platform::settings.features.turn_off') }}</button>
                        @if ($flag['byStaff'])<button name="value" value="default" class="min-h-11 rounded-md border border-line px-4 py-2">{{ __('platform::settings.features.use_default') }}</button>@endif
                    </form>
                @endcan
            </li>
        @endforeach
    </ul>
</div>
@endsection
