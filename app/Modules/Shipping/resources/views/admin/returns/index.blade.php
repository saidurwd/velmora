@extends('layouts.admin')

@section('title', __('shipping::fulfilment.returns.title'))

@section('content')
<div class="space-y-6">
    <h1 class="text-2xl font-bold">{{ __('shipping::fulfilment.returns.title') }}</h1>
    <form method="GET" action="{{ route('admin.shipping.returns.index') }}" class="grid gap-3 sm:grid-cols-3 sm:items-end">
        <div><label for="status" class="block text-sm font-medium">{{ __('shipping::fulfilment.shipments.status') }}</label>
            <select id="status" name="status" class="block w-full rounded-md border border-line px-3 py-2"><option value="">{{ __('shipping::fulfilment.all') }}</option>
                @foreach ($statuses as $s)<option value="{{ $s->value }}" @selected($status === $s)>{{ __('shipping::fulfilment.returns.status.'.$s->value) }}</option>@endforeach</select></div>
        <div><x-platform::btn type="submit" size="sm">{{ __('shipping::fulfilment.filter') }}</x-platform::btn></div>
    </form>
    @if ($returns->isEmpty())
        <p class="text-muted">{{ __('shipping::fulfilment.returns.empty') }}</p>
    @else
    <div class="overflow-x-auto"><table class="w-full text-sm">
        <caption class="sr-only">{{ __('shipping::fulfilment.returns.title') }}</caption>
        <thead><tr class="border-b border-line">@foreach (['return', 'order', 'kind', 'status', 'reason', 'asked'] as $col)<th scope="col" class="py-2 pe-3 text-start">{{ __('shipping::fulfilment.returns.cols.'.$col) }}</th>@endforeach</tr></thead>
        <tbody>
        @foreach ($returns as $r)
            <tr class="border-b border-line">
                <td class="py-2 pe-3"><a class="underline" href="{{ route('admin.shipping.returns.show', $r->public_id) }}">{{ strtoupper(substr($r->public_id, -8)) }}</a></td>
                <td class="py-2 pe-3">{{ $orders[$r->order_id]['number'] ?? '' }}</td><td class="py-2 pe-3">{{ __('shipping::fulfilment.returns.kind.'.$r->kind) }}</td>
                <td class="py-2 pe-3"><x-platform::badge :tone="match ($r->status->value) { 'closed' => 'success', 'rejected', 'cancelled' => 'neutral', 'requested' => 'warning', default => 'brand' }">{{ __('shipping::fulfilment.returns.status.'.$r->status->value) }}</x-platform::badge></td>
                <td class="py-2 pe-3">{{ __('shipping::fulfilment.returns.reason_code.'.$r->reason_code) }}</td><td class="py-2 pe-3">{{ \App\Modules\Platform\Support\Fmt::dateTime($r->created_at) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    {{ $returns->links() }}
    @endif
</div>
@endsection
