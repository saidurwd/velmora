@extends('layouts.account')

@section('title', __('shipping::returns.mine_title'))

@section('account')
@php $fmt = fn ($d) => \App\Modules\Platform\Support\Fmt::date(\Carbon\CarbonImmutable::parse($d, 'UTC')); @endphp
<div class="max-w-2xl space-y-6">
    <h1 class="text-2xl font-bold">{{ __('shipping::returns.mine_title') }}</h1>
    @error('return')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror
    @if ($returns === [])
        <p class="text-muted">{{ __('shipping::returns.mine_none') }}</p>
    @else
        <ul class="space-y-4">
            @foreach ($returns as $r)
                <li class="space-y-2 rounded-lg border border-line p-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="font-medium">{{ __('shipping::returns.order') }} {{ $r['order'] }}</span>
                        <x-platform::badge :tone="in_array($r['status'], ['closed'], true) ? 'success' : (in_array($r['status'], ['rejected'], true) ? 'danger' : 'neutral')">{{ __('shipping::returns.status.'.$r['status']) }}</x-platform::badge>
                    </div>
                    <p class="text-sm text-muted">{{ __('shipping::returns.asked_on', ['date' => $fmt($r['created_at'])]) }} · {{ __('shipping::returns.reason.'.$r['reason_code']) }}</p>
                    <ul class="list-disc ps-5">@foreach ($r['items'] as $item)<li>{{ $item['name'] }} × {{ \App\Modules\Platform\Support\Fmt::number($item['quantity']) }}</li>@endforeach</ul>
                    <p class="text-sm">{{ __('shipping::returns.next.'.$r['status']) }}</p>
                    @if ($r['status'] === 'authorized')<p class="text-sm">{{ __('shipping::returns.payer.'.$r['payer']) }}</p>@endif
                    @if ($r['note'] && in_array($r['status'], ['rejected', 'authorized'], true))<p class="text-sm">{{ __('shipping::returns.shop_note', ['note' => $r['note']]) }}</p>@endif
                    @if (in_array($r['status'], ['requested', 'authorized'], true))
                        <form method="POST" action="{{ route('account.returns.withdraw', $r['id']) }}">@csrf
                            <x-platform::btn type="submit" size="sm" variant="secondary">{{ __('shipping::returns.withdraw') }}<span class="sr-only"> — {{ $r['order'] }}</span></x-platform::btn>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
@endsection
