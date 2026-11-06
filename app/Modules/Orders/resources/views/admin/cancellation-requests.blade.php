@extends('layouts.admin')

@section('title', __('orders::cancel-requests.queue_title'))

@section('content')
<div class="max-w-5xl space-y-6">
    <h1 class="text-2xl font-bold">{{ __('orders::cancel-requests.queue_title') }}</h1>
    <p class="text-sm text-muted">{{ __('orders::cancel-requests.queue_help') }}</p>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    @error('request')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror
    <form method="GET" action="{{ route('admin.orders.cancellations.index') }}" class="flex items-end gap-3">
        <div><label for="status" class="block text-sm font-medium">{{ __('orders::cancel-requests.state') }}</label>
            <select id="status" name="status" class="mt-1 block rounded-md border border-line px-3 py-2">@foreach (['requested', 'approved', 'rejected', 'withdrawn'] as $s)<option value="{{ $s }}" @selected($status === $s)>{{ __('orders::cancel-requests.status.'.$s) }}</option>@endforeach</select></div>
        <x-platform::btn type="submit" size="sm">{{ __('orders::cancel-requests.filter') }}</x-platform::btn>
    </form>
    @if ($page->isEmpty())
        <p class="text-muted">{{ __('orders::cancel-requests.none') }}</p>
    @else
        <ul class="space-y-4">
            @foreach ($page as $r)
                <li class="space-y-2 rounded-lg border border-line p-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <a class="font-medium underline" href="{{ route('admin.orders.show', $r->order_public_id) }}">{{ $r->order_number }}</a>
                        <span class="text-sm text-muted">{{ __('orders::orders.status.'.$r->order_status) }} · {{ __('orders::orders.payment.'.$r->payment_status) }} · {{ \App\Modules\Platform\Support\Fmt::money(\App\Modules\Platform\DTOs\Money::of((int) $r->grand_total_minor, $r->currency)) }}</span>
                    </div>
                    <p class="text-sm text-muted">{{ __('orders::cancel-requests.asked_on', ['date' => \App\Modules\Platform\Support\Fmt::dateTime(\Carbon\CarbonImmutable::parse($r->created_at, 'UTC'))]) }}</p>
                    @if ($r->note)<p>{{ __('orders::cancel-requests.customer_said', ['note' => $r->note]) }}</p>@endif
                    @if ($r->decision_note)<p class="text-sm">{{ __('orders::cancel-requests.we_said', ['note' => $r->decision_note]) }}</p>@endif
                    @if ($r->status === 'requested')
                        <div class="flex flex-wrap items-end gap-4">
                            <form method="POST" action="{{ route('admin.orders.cancellations.approve', $r->public_id) }}" class="space-y-1">@csrf
                                <p class="max-w-md text-xs text-muted">{{ __('orders::cancel-requests.approve_help') }}</p>
                                <x-platform::btn type="submit" size="sm">{{ __('orders::cancel-requests.approve') }}<span class="sr-only"> — {{ $r->order_number }}</span></x-platform::btn>
                            </form>
                            <form method="POST" action="{{ route('admin.orders.cancellations.reject', $r->public_id) }}" class="flex flex-wrap items-end gap-2">@csrf
                                <div><label for="why-{{ $r->public_id }}" class="block text-xs font-medium">{{ __('orders::cancel-requests.reason_label') }}</label>
                                    <input id="why-{{ $r->public_id }}" name="note" maxlength="255" required class="mt-1 rounded-md border border-line px-2 py-1"></div>
                                <x-platform::btn type="submit" size="sm" variant="secondary">{{ __('orders::cancel-requests.reject') }}<span class="sr-only"> — {{ $r->order_number }}</span></x-platform::btn>
                            </form>
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
        {{ $page->links() }}
    @endif
</div>
@endsection
