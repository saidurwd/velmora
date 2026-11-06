@extends('layouts.admin')

@section('title', __('engagement::support.canned_title'))

@section('content')
<div class="max-w-4xl space-y-6">
    <h1 class="text-2xl font-bold">{{ __('engagement::support.canned_title') }}</h1>
    <p class="text-sm text-muted">{{ __('engagement::support.canned_help') }}</p>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    <p><x-platform::btn :href="route('admin.support.canned.create')">{{ __('engagement::support.canned_new') }}</x-platform::btn></p>
    @if ($replies === [])
        <p class="text-muted">{{ __('engagement::support.canned_none') }}</p>
    @else
        <ul class="divide-y divide-line rounded-lg border border-line">
            @foreach ($replies as $r)
                <li class="flex flex-wrap items-center justify-between gap-3 p-3">
                    <span>{{ $r['titles'][app()->getLocale()] ?? reset($r['titles']) }} @unless ($r['active'])<x-platform::badge tone="neutral">{{ __('engagement::support.inactive') }}</x-platform::badge>@endunless</span>
                    <span class="flex gap-3">
                        <a class="underline" href="{{ route('admin.support.canned.edit', $r['id']) }}">{{ __('engagement::support.edit') }}</a>
                        <form method="POST" action="{{ route('admin.support.canned.destroy', $r['id']) }}">@csrf @method('DELETE')<button type="submit" class="underline text-danger">{{ __('engagement::support.delete') }}</button></form>
                    </span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
@endsection
