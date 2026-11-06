@extends('layouts.account')

@section('title', __('engagement::support.title'))

@section('account')
@php $fmt = fn ($d) => \App\Modules\Platform\Support\Fmt::date(\Carbon\CarbonImmutable::parse($d, 'UTC')); @endphp
<div class="max-w-2xl space-y-6">
    <h1 class="text-2xl font-bold">{{ __('engagement::support.title') }}</h1>
    <p>{{ __('engagement::support.intro') }} <a class="underline" href="{{ route('help') }}">{{ __('engagement::support.help_link') }}</a></p>
    <p><x-platform::btn :href="route('account.support.create')">{{ __('engagement::support.new_button') }}</x-platform::btn></p>
    @if ($tickets === [])
        <p class="text-muted">{{ __('engagement::support.none') }}</p>
    @else
        <ul class="space-y-3">
            @foreach ($tickets as $t)
                <li class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-line p-3">
                    <div class="min-w-0">
                        <a class="font-medium underline" href="{{ route('account.support.show', $t->public_id) }}">{{ $t->subject }}</a>
                        <div class="text-sm text-muted">{{ $t->reference }} · {{ __('engagement::support.category.'.$t->category) }} · {{ $fmt($t->last_message_at) }}</div>
                    </div>
                    <x-platform::badge :tone="$t->status === 'answered' ? 'success' : 'neutral'">{{ __('engagement::support.status.'.$t->status) }}</x-platform::badge>
                </li>
            @endforeach
        </ul>
    @endif
</div>
@endsection
