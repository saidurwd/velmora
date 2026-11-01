@extends('layouts.admin')

@section('title', __('shipping::fulfilment.issues.title'))

@section('content')
<div class="space-y-6">
    <h1 class="text-2xl font-bold">{{ __('shipping::fulfilment.issues.title') }}</h1>
    <p class="text-sm text-muted">{{ __('shipping::fulfilment.issues.help') }}</p>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    @error('issue')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror
    <nav aria-label="{{ __('shipping::fulfilment.issues.title') }}" class="flex gap-4 text-sm">
        @foreach (['open', 'resolved', 'ignored'] as $tab)<a href="{{ route('admin.shipping.issues.index', ['status' => $tab]) }}" aria-current="{{ $status === $tab ? 'page' : 'false' }}" class="underline {{ $status === $tab ? 'font-semibold' : '' }}">{{ __('shipping::fulfilment.issues.tabs.'.$tab) }}</a>@endforeach
    </nav>
    @if ($issues->isEmpty())
        <p class="text-muted">{{ __('shipping::fulfilment.issues.empty') }}</p>
    @else
    <div class="overflow-x-auto"><table class="w-full text-sm">
        <caption class="sr-only">{{ __('shipping::fulfilment.issues.title') }}</caption>
        <thead><tr class="border-b border-line">@foreach (['type', 'parcel', 'details', 'raised'] as $col)<th scope="col" class="py-2 pe-3 text-start">{{ __('shipping::fulfilment.issues.cols.'.$col) }}</th>@endforeach<th scope="col" class="py-2 pe-3 text-start">{{ $status === 'open' ? __('shipping::fulfilment.issues.close') : __('shipping::fulfilment.issues.cols.closed') }}</th></tr></thead>
        <tbody>
        @foreach ($issues as $x)
            <tr class="border-b border-line align-top">
                <td class="py-2 pe-3">{{ __('shipping::fulfilment.issues.type.'.$x->type) }}</td>
                <td class="py-2 pe-3">@if (isset($parcels[$x->shipment_id]))<a class="underline" href="{{ route('admin.shipping.shipments.show', $parcels[$x->shipment_id]) }}">{{ strtoupper(substr($parcels[$x->shipment_id], -8)) }}</a>@else{{ $x->reference }}@endif</td>
                <td class="py-2 pe-3">@foreach ((array) $x->details as $k => $v)@if (is_scalar($v))<span class="me-2">{{ $k }}: {{ $v }}</span>@endif @endforeach</td>
                <td class="py-2 pe-3">{{ \App\Modules\Platform\Support\Fmt::dateTime($x->created_at) }}</td>
                <td class="py-2 pe-3">
                    @if ($status !== 'open')
                        {{ $x->resolution }}
                    @elseif ($can)
                    <form method="POST" action="{{ route('admin.shipping.issues.resolve', $x->public_id) }}" class="flex flex-wrap items-end gap-2">@csrf
                        <div><label for="out-{{ $x->id }}" class="block text-xs">{{ __('shipping::fulfilment.issues.outcome') }}</label><select id="out-{{ $x->id }}" name="outcome" class="rounded-md border border-line px-2 py-1"><option value="resolved">{{ __('shipping::fulfilment.issues.resolved') }}</option><option value="ignored">{{ __('shipping::fulfilment.issues.ignored') }}</option></select></div>
                        <div><label for="note-{{ $x->id }}" class="block text-xs">{{ __('shipping::fulfilment.issues.note') }}</label><input id="note-{{ $x->id }}" name="note" required maxlength="255" class="rounded-md border border-line px-2 py-1"></div>
                        <x-platform::btn type="submit" size="sm">{{ __('shipping::fulfilment.issues.close') }}</x-platform::btn>
                    </form>
                    @endif
                </td></tr>
        @endforeach
        </tbody>
    </table></div>
    {{ $issues->links() }}
    @endif
</div>
@endsection
