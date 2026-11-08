@extends('layouts.admin')

@section('title', __('cms::policies.title'))

@section('content')
<div class="max-w-5xl space-y-6">
    <h1 class="text-2xl font-bold">{{ __('cms::policies.title') }}</h1>
    <p class="text-muted">{{ __('cms::policies.intro') }}</p>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    <x-platform::form.errors />

    @foreach ($kinds as $kind)
        @php
            $page = $pages->get($kind->value);
            $history = $releases->get($kind->value, collect());
            $current = $history->first();
        @endphp
        <section aria-labelledby="k-{{ $kind->value }}" class="space-y-3 rounded-md border border-line p-4">
            <h2 id="k-{{ $kind->value }}" class="text-lg font-semibold">{{ __('cms::policies.kinds.'.$kind->value) }}</h2>
            <p class="text-sm text-muted">{{ __('cms::policies.asked.'.$kind->value) }}</p>

            @if ($page)
                <p>{{ __('cms::policies.page') }}: <a class="underline" href="{{ route('admin.cms.pages.edit', $page) }}">{{ $page->draftVersion?->title() ?: '—' }}</a></p>
            @else
                <p class="text-warning">{{ __('cms::policies.no_page') }}</p>
            @endif

            @if ($current)
                <p>{{ __('cms::policies.current') }}: {{ __('cms::policies.account.version', ['number' => $versions[$current->version_id] ?? '?']) }} · {{ __('cms::policies.account.in_force', ['date' => \App\Modules\Platform\Support\Fmt::dateTime(\Carbon\CarbonImmutable::parse($current->effective_at, 'UTC'))]) }}
                    · {{ $current->acceptance_required ? __('cms::policies.required_yes') : __('cms::policies.required_no') }}</p>
                @can('policies.acceptance.view')
                    @php($byChannel = $counts->get($current->id, collect()))
                    <p>{{ __('cms::policies.accepted_count', ['count' => $byChannel->sum('total')]) }}
                        @foreach ($byChannel as $c) · {{ __('cms::policies.channels.'.$c->channel) }}: {{ $c->total }}@endforeach</p>
                @endcan
            @elseif ($page)
                <p class="text-muted">{{ __('cms::policies.not_released') }}</p>
            @endif

            @can('cms.policies.manage')
                <form method="POST" action="{{ route('admin.cms.policies.assign', ['kind' => $kind->value]) }}" class="flex flex-wrap items-end gap-3">
                    @csrf
                    <span>
                        <label for="page-{{ $kind->value }}" class="block text-sm font-medium">{{ __('cms::policies.choose') }}</label>
                        <select id="page-{{ $kind->value }}" name="page" class="rounded-md border border-line px-3 py-2">
                            <option value="">—</option>
                            @foreach ($candidates as $c)<option value="{{ $c->public_id }}">{{ $c->draftVersion?->title() ?: $c->public_id }}</option>@endforeach
                        </select>
                    </span>
                    <button class="min-h-11 rounded-md border border-line px-4 py-2">{{ $page ? __('cms::policies.change') : __('cms::policies.assign') }}</button>
                </form>
            @endcan
        </section>
    @endforeach
</div>
@endsection
