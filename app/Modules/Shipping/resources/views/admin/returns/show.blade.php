@extends('layouts.admin')

@section('title', __('shipping::fulfilment.returns.show_title', ['id' => strtoupper(substr($return->public_id, -8))]))

@php
    $fmt = fn (int $minor, string $cur = 'BDT') => \App\Modules\Platform\Support\Fmt::money(\App\Modules\Platform\DTOs\Money::of($minor, $cur));
    $status = $return->status->value;
@endphp

@section('content')
<div class="space-y-8">
    <div><a class="text-sm underline" href="{{ route('admin.shipping.returns.index') }}">{{ __('shipping::fulfilment.back') }}</a>
        <h1 class="mt-1 text-2xl font-bold">{{ __('shipping::fulfilment.returns.show_title', ['id' => strtoupper(substr($return->public_id, -8))]) }}</h1></div>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    @error('return')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror

    <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
        <div><dt class="text-muted">{{ __('shipping::fulfilment.order') }}</dt><dd>@if ($order)<a class="underline" href="{{ route('admin.shipping.orders.show', $order['public_id']) }}">{{ $order['number'] }}</a>@endif</dd></div>
        <div><dt class="text-muted">{{ __('shipping::fulfilment.returns.cols.status') }}</dt><dd>{{ __('shipping::fulfilment.returns.status.'.$status) }}</dd></div>
        <div><dt class="text-muted">{{ __('shipping::fulfilment.returns.cols.kind') }}</dt><dd>{{ __('shipping::fulfilment.returns.kind.'.$return->kind) }}</dd></div>
        <div><dt class="text-muted">{{ __('shipping::fulfilment.returns.cols.reason') }}</dt><dd>{{ __('shipping::fulfilment.returns.reason_code.'.$return->reason_code) }}@if ($return->reason) — {{ $return->reason }}@endif</dd></div>
        <div><dt class="text-muted">{{ __('shipping::fulfilment.returns.payer') }}</dt><dd>{{ __('shipping::fulfilment.returns.payer_value.'.$return->shipping_cost_payer) }}</dd></div>
        @if ($return->decision_note)<div><dt class="text-muted">{{ __('shipping::fulfilment.returns.decision_note') }}</dt><dd>{{ $return->decision_note }}</dd></div>@endif
        @if ($return->refund_suggested_minor !== null)<div><dt class="text-muted">{{ __('shipping::fulfilment.returns.refund') }}</dt><dd>{{ $fmt($return->refund_suggested_minor, $return->currency) }} <span class="text-muted">— {{ __('shipping::fulfilment.returns.refund_note') }}</span></dd></div>@endif
    </dl>

    @if ($status === 'authorized' && $canHandle)
    <form method="POST" action="{{ route('admin.shipping.returns.receive', $return->public_id) }}" class="space-y-3">@csrf
    @endif
    <section aria-labelledby="items-h" class="space-y-2">
        <h2 id="items-h" class="text-lg font-semibold">{{ __('shipping::fulfilment.returns.items') }}</h2>
        <div class="overflow-x-auto"><table class="w-full text-sm">
            <caption class="sr-only">{{ __('shipping::fulfilment.returns.items') }}</caption>
            <thead><tr class="border-b border-line"><th scope="col" class="py-2 pe-3 text-start">{{ __('shipping::fulfilment.returns.item') }}</th><th scope="col" class="py-2 pe-3 text-start">{{ __('shipping::fulfilment.returns.asked_qty') }}</th><th scope="col" class="py-2 pe-3 text-start">{{ __('shipping::fulfilment.returns.received_qty') }}</th><th scope="col" class="py-2 pe-3 text-start">{{ __('shipping::fulfilment.returns.outcome_col') }}</th></tr></thead>
            <tbody>
            @foreach ($return->items as $i)
                <tr class="border-b border-line align-top"><td class="py-2 pe-3">{{ $names[$i->order_item_id] ?? '#'.$i->order_item_id }}</td><td class="py-2 pe-3">{{ $i->quantity }}</td>
                    <td class="py-2 pe-3">@if ($status === 'authorized' && $canHandle)<input type="number" min="0" max="{{ $i->quantity }}" name="received[{{ $i->order_item_id }}]" aria-label="{{ __('shipping::fulfilment.returns.received_qty') }}: {{ $names[$i->order_item_id] ?? '' }}" class="w-24 rounded-md border border-line px-2 py-1">@else{{ $i->received_qty ?? __('shipping::fulfilment.none') }}@endif</td>
                    <td class="py-2 pe-3">
                        @if ($i->outcome !== null){{ __('shipping::fulfilment.returns.outcome.'.$i->outcome) }}@if ($i->note) — {{ $i->note }}@endif
                        @elseif ($status === 'received' && $canHandle && (int) $i->received_qty > 0)
                        <form method="POST" action="{{ route('admin.shipping.returns.inspect', $return->public_id) }}" class="flex flex-wrap items-end gap-2">@csrf
                            <input type="hidden" name="line" value="{{ $i->order_item_id }}">
                            <div><label for="o-{{ $i->id }}" class="block text-xs">{{ __('shipping::fulfilment.returns.inspect') }}</label><select id="o-{{ $i->id }}" name="outcome" class="rounded-md border border-line px-2 py-1">@foreach ($outcomes as $o)<option value="{{ $o->value }}">{{ __('shipping::fulfilment.returns.outcome.'.$o->value) }}</option>@endforeach</select></div>
                            <div><label for="n-{{ $i->id }}" class="block text-xs">{{ __('shipping::fulfilment.returns.note') }}</label><input id="n-{{ $i->id }}" name="note" maxlength="255" class="rounded-md border border-line px-2 py-1"></div>
                            <x-platform::btn type="submit" size="sm">{{ __('shipping::fulfilment.returns.inspect_btn') }}</x-platform::btn>
                        </form>
                        @else{{ __('shipping::fulfilment.none') }}@endif
                    </td></tr>
            @endforeach
            </tbody>
        </table></div>
    </section>
    @if ($status === 'authorized' && $canHandle)
        <p class="text-sm text-muted">{{ __('shipping::fulfilment.returns.receive_help') }}</p>
        <x-platform::btn type="submit" size="sm">{{ __('shipping::fulfilment.returns.receive_btn') }}</x-platform::btn>
    </form>
    @endif

    @if ($canDecide && $status === 'requested')
    <form method="POST" action="{{ route('admin.shipping.returns.authorize', $return->public_id) }}" class="space-y-2 rounded-lg border border-line p-4">@csrf
        <h2 class="font-semibold">{{ __('shipping::fulfilment.returns.allow') }}</h2>
        <label for="payer" class="block text-sm font-medium">{{ __('shipping::fulfilment.returns.payer') }}</label>
        <select id="payer" name="payer" class="block w-full rounded-md border border-line px-3 py-2">@foreach (['shop', 'customer', 'none'] as $p)<option value="{{ $p }}">{{ __('shipping::fulfilment.returns.payer_value.'.$p) }}</option>@endforeach</select>
        <x-platform::btn type="submit" size="sm">{{ __('shipping::fulfilment.returns.allow') }}</x-platform::btn>
    </form>
    <form method="POST" action="{{ route('admin.shipping.returns.reject', $return->public_id) }}" class="space-y-2 rounded-lg border border-line p-4">@csrf
        <h2 class="font-semibold">{{ __('shipping::fulfilment.returns.refuse') }}</h2>
        <label for="reject-reason" class="block text-sm font-medium">{{ __('shipping::fulfilment.returns.reason') }}</label><input id="reject-reason" name="reason" required maxlength="255" class="block w-full rounded-md border border-line px-3 py-2">
        <x-platform::btn type="submit" size="sm" variant="secondary">{{ __('shipping::fulfilment.returns.refuse') }}</x-platform::btn>
    </form>
    @endif
    @if ($canDecide && in_array($status, ['requested', 'authorized'], true))
    <form method="POST" action="{{ route('admin.shipping.returns.cancel', $return->public_id) }}" class="space-y-2 rounded-lg border border-line p-4">@csrf
        <h2 class="font-semibold">{{ __('shipping::fulfilment.returns.cancel') }}</h2>
        <label for="cancel-reason" class="block text-sm font-medium">{{ __('shipping::fulfilment.returns.reason') }}</label><input id="cancel-reason" name="reason" required maxlength="255" class="block w-full rounded-md border border-line px-3 py-2">
        <x-platform::btn type="submit" size="sm" variant="secondary">{{ __('shipping::fulfilment.returns.cancel') }}</x-platform::btn>
    </form>
    @endif
</div>
@endsection
