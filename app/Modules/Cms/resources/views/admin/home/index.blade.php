@extends('layouts.admin')

@section('title', __('cms::home.title'))

@section('content')
<div class="max-w-4xl space-y-8">
    <div class="flex items-center justify-between gap-4">
        <h1 class="text-2xl font-bold">{{ __('cms::home.title') }}</h1>
        @can('cms.edit')<a href="{{ route('admin.cms.home.create') }}" class="inline-flex min-h-11 items-center rounded-md bg-brand-600 px-4 py-2 font-medium text-white hover:bg-brand-700">{{ __('cms::home.add') }}</a>@endcan
    </div>
    <p class="text-muted">{{ __('cms::home.intro') }}</p>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif

    @if ($sections === [])
        <p class="text-muted">{{ __('cms::home.empty') }}</p>
    @else
        <section aria-labelledby="list-h" class="space-y-3">
            <h2 id="list-h" class="text-lg font-semibold">{{ __('cms::home.sections') }}</h2>
            <ul class="space-y-2">
                @foreach ($sections as $s)
                    @php($row = $s['row'])
                    <li class="flex flex-wrap items-center gap-3 rounded-md border border-line p-3">
                        <span class="font-medium">{{ __('cms::home.types.'.$row->type) }}@if ($row->title) — {{ $row->title }}@endif</span>
                        <span class="rounded bg-surface-alt px-2 py-0.5 text-xs">{{ __('cms::home.states.'.$s['state']) }}</span>
                        @if ($s['starts'] || $s['ends'])<span class="text-sm text-muted">{{ $s['starts'] ? \App\Modules\Platform\Support\Fmt::dateTime($s['starts']) : '…' }} → {{ $s['ends'] ? \App\Modules\Platform\Support\Fmt::dateTime($s['ends']) : '…' }}</span>@endif
                        <span class="ms-auto flex flex-wrap items-center gap-2">
                            @can('cms.edit')
                                <form method="POST" action="{{ route('admin.cms.home.move', $row->public_id) }}" class="inline">@csrf<input type="hidden" name="direction" value="up"><button class="min-h-11 rounded-md border border-line px-3 py-2" aria-label="{{ __('cms::home.move_up') }}: {{ __('cms::home.types.'.$row->type) }}">↑</button></form>
                                <form method="POST" action="{{ route('admin.cms.home.move', $row->public_id) }}" class="inline">@csrf<input type="hidden" name="direction" value="down"><button class="min-h-11 rounded-md border border-line px-3 py-2" aria-label="{{ __('cms::home.move_down') }}: {{ __('cms::home.types.'.$row->type) }}">↓</button></form>
                            @endcan
                            <a class="underline" href="{{ route('admin.cms.home.edit', $row->public_id) }}">{{ __('cms::home.edit') }}</a>
                            @can('cms.edit')
                                <form method="POST" action="{{ route('admin.cms.home.destroy', $row->public_id) }}" class="inline" onsubmit="return confirm('{{ __('cms::home.confirm_delete') }}')">@csrf @method('DELETE')<button class="underline text-danger">{{ __('cms::admin.delete') }}</button></form>
                            @endcan
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>

        <section aria-labelledby="preview-h" class="space-y-3">
            <h2 id="preview-h" class="text-lg font-semibold">{{ __('cms::home.preview') }}</h2>
            <p class="text-sm text-muted">{{ __('cms::home.preview_help') }}</p>
            <ol class="space-y-3">
                @foreach ($sections as $s)
                    @php($row = $s['row'])
                    <li class="rounded-md border border-line p-3 {{ $s['state'] === 'live' ? '' : 'opacity-60' }}">
                        <p class="text-sm font-medium">{{ $loop->iteration }}. {{ __('cms::home.types.'.$row->type) }} · {{ __('cms::home.states.'.$s['state']) }}</p>
                        @if ($row->title)<p class="text-lg font-semibold">{{ $row->title }}</p>@endif
                        @if ($s['body'])<div class="mt-2 space-y-2 leading-relaxed [&_a]:text-brand-700 [&_a]:underline">{!! $s['body'] !!}</div>@endif
                        @if ($row->type === 'collection')<p class="text-sm text-muted">{{ __('cms::home.preview_collection') }}</p>@endif
                    </li>
                @endforeach
            </ol>
        </section>
    @endif
</div>
@endsection
