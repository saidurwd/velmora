@extends('layouts.admin')

@section('title', __('shipping::fulfilment.order_page.title', ['number' => $order->number]))

@section('content')
@php $left = collect($order->lines)->filter(fn ($l) => $l->productType === 'physical' && $l->remaining() > 0); @endphp
<div class="space-y-8">
    <div><a class="text-sm underline" href="{{ route('admin.shipping.shipments.index') }}">{{ __('shipping::fulfilment.back') }}</a>
        <h1 class="mt-1 text-2xl font-bold">{{ __('shipping::fulfilment.order_page.title', ['number' => $order->number]) }}</h1></div>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    @error('parcel')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror

    @if ($order->notReadyReason !== null)
        <x-platform::alert type="info">{{ __('shipping::fulfilment.order_page.not_ready') }} {{ __('shipping::shipments.errors.'.$order->notReadyReason) }}</x-platform::alert>
    @endif

    <section aria-labelledby="send-h" class="space-y-3">
        <h2 id="send-h" class="text-lg font-semibold">{{ __('shipping::fulfilment.order_page.to_send') }}</h2>
        @if ($left->isEmpty())
            <p class="text-muted">{{ __('shipping::fulfilment.order_page.nothing_left') }}</p>
        @else
        @php $canMake = $can && $order->notReadyReason === null; @endphp
        <form method="POST" action="{{ route('admin.shipping.shipments.store', $order->publicId) }}" class="space-y-3">
            @csrf
            @if ($canMake)<p class="text-sm text-muted">{{ __('shipping::fulfilment.order_page.help') }}</p>@endif
            <div class="overflow-x-auto"><table class="w-full text-sm">
                <caption class="sr-only">{{ __('shipping::fulfilment.order_page.to_send') }}</caption>
                <thead><tr class="border-b border-line">
                    <th scope="col" class="py-2 pe-3 text-start">{{ __('shipping::fulfilment.order_page.item') }}</th><th scope="col" class="py-2 pe-3 text-start">{{ __('shipping::fulfilment.order_page.ordered') }}</th>
                    <th scope="col" class="py-2 pe-3 text-start">{{ __('shipping::fulfilment.order_page.sent') }}</th><th scope="col" class="py-2 pe-3 text-start">{{ __('shipping::fulfilment.order_page.left') }}</th>
                    @if ($canMake)<th scope="col" class="py-2 pe-3 text-start">{{ __('shipping::fulfilment.order_page.put_in') }}</th>@endif
                </tr></thead>
                <tbody>
                @foreach ($left as $l)
                    <tr class="border-b border-line"><td class="py-2 pe-3">{{ $names[$l->orderItemId] ?? $l->name }} <span class="text-muted">({{ $l->sku }})</span></td><td class="py-2 pe-3">{{ $l->quantity }}</td><td class="py-2 pe-3">{{ $l->fulfilledQty }}</td><td class="py-2 pe-3">{{ $l->remaining() }}</td>
                        @if ($canMake)<td class="py-2 pe-3"><input type="number" min="0" max="{{ $l->remaining() }}" name="quantities[{{ $l->orderItemId }}]" value="{{ old('quantities.'.$l->orderItemId, 0) }}" aria-label="{{ __('shipping::fulfilment.order_page.put_in') }}: {{ $names[$l->orderItemId] ?? $l->name }}" class="w-24 rounded-md border border-line px-2 py-1"></td>@endif</tr>
                @endforeach
                </tbody>
            </table></div>
            @if ($canMake)
            <div class="grid gap-3 sm:grid-cols-2">
                <div><label for="courier" class="block text-sm font-medium">{{ __('shipping::fulfilment.order_page.courier') }}</label>
                    <select id="courier" name="courier" class="block w-full rounded-md border border-line px-3 py-2"><option value="">{{ __('shipping::fulfilment.order_page.default_courier') }}</option>
                        @foreach ($accounts as $a)<option value="{{ $a->public_id }}" @selected(old('courier') === $a->public_id)>{{ $a->label }}</option>@endforeach</select></div>
                <div><label for="note" class="block text-sm font-medium">{{ __('shipping::fulfilment.order_page.note') }}</label><input id="note" name="note" value="{{ old('note') }}" maxlength="255" class="block w-full rounded-md border border-line px-3 py-2"></div>
            </div>
            <x-platform::btn type="submit" size="sm">{{ __('shipping::fulfilment.order_page.make_btn') }}</x-platform::btn>
            @endif
        </form>
        @endif
    </section>

    <section aria-labelledby="parcels-h" class="space-y-2">
        <h2 id="parcels-h" class="text-lg font-semibold">{{ __('shipping::fulfilment.order_page.parcels') }}</h2>
        @if ($parcels->isEmpty())
            <p class="text-muted">{{ __('shipping::fulfilment.order_page.no_parcels') }}</p>
        @else
        <ul class="space-y-1">
            @foreach ($parcels as $p)
                <li><a class="underline" href="{{ route('admin.shipping.shipments.show', $p->public_id) }}">{{ strtoupper(substr($p->public_id, -8)) }}</a> — {{ __('shipping::fulfilment.status.'.$p->status->value) }}</li>
            @endforeach
        </ul>
        @endif
    </section>
</div>
@endsection
