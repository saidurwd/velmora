@extends('layouts.account')

@section('title', __('engagement::reviews.mine_title'))

@section('account')
@php $fmt = fn ($d) => \App\Modules\Platform\Support\Fmt::date(\Carbon\CarbonImmutable::parse($d, 'UTC')); @endphp
<div class="max-w-2xl space-y-6">
    <h1 class="text-2xl font-bold">{{ __('engagement::reviews.mine_title') }}</h1>
    @error('review')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror
    @if ($reviews === [])
        <p class="text-muted">{{ __('engagement::reviews.mine_none') }}</p>
    @else
        <ul class="space-y-4">
            @foreach ($reviews as $r)
                <li class="space-y-2 rounded-lg border border-line p-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="font-medium">{{ $r['name'] }}</span>
                        <x-platform::badge :tone="$r['status'] === 'published' ? 'success' : ($r['status'] === 'rejected' ? 'danger' : 'neutral')">{{ __('engagement::reviews.status.'.$r['status']) }}</x-platform::badge>
                    </div>
                    <p><span aria-hidden="true" class="text-warning">{{ str_repeat('★', $r['rating']) }}{{ str_repeat('☆', 5 - $r['rating']) }}</span><span class="sr-only">{{ __('engagement::reviews.stars', ['count' => \App\Modules\Platform\Support\Fmt::number($r['rating'])]) }}</span> <span class="text-sm text-muted">· {{ $fmt($r['created_at']) }}</span></p>
                    @if ($r['title'])<p class="font-medium">{{ $r['title'] }}</p>@endif
                    <p class="whitespace-pre-line">{{ $r['body'] }}</p>
                    @if ($r['status'] === 'rejected' && $r['reason'])<p class="text-sm">{{ __('engagement::reviews.reason', ['reason' => $r['reason']]) }}</p>@endif
                    @if (in_array($r['status'], ['pending', 'published'], true))
                        <form method="POST" action="{{ route('account.reviews.withdraw', $r['id']) }}">@csrf
                            <x-platform::btn type="submit" size="sm" variant="secondary">{{ __('engagement::reviews.withdraw') }}<span class="sr-only"> — {{ $r['name'] }}</span></x-platform::btn>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
@endsection
