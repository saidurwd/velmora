@extends('layouts.admin')

@section('title', __('orders::orders.order', ['number' => $order->order_number]))

@php
    $locale = app()->getLocale();
    $fmt = fn (int $minor) => \App\Modules\Platform\Support\Fmt::money(\App\Modules\Platform\DTOs\Money::of($minor, $order->currency));
    $phone = fn (string $p) => $canSeePhone ? $p : (\App\Modules\Platform\DTOs\PhoneNumber::tryParse($p)?->masked() ?? '—');
    $axes = [
        'status' => [$order->status->value, $order->status->next()],
        'payment' => [$order->payment_status->value, $order->payment_status->next()],
        'fulfillment' => [$order->fulfillment_status->value, $order->fulfillment_status->next()],
    ];
    $methodKey = 'orders::orders.methods.'.$order->payment_method_code;
    $method = \Illuminate\Support\Facades\Lang::has($methodKey) ? __($methodKey) : $order->payment_method_code;
@endphp

@section('content')
<div class="space-y-8">
    <div>
        <a class="text-sm underline" href="{{ route('admin.orders.index') }}">← {{ __('orders::orders.back') }}</a>
        <h1 class="mt-1 text-2xl font-bold">{{ __('orders::orders.order', ['number' => $order->order_number]) }}</h1>
        <p class="text-muted">{{ \App\Modules\Platform\Support\Fmt::dateTime($order->placed_at) }} · {{ __('orders::orders.channels.'.$order->channel->value) }}@if ($order->needs_review) · <strong class="text-danger">{{ __('orders::orders.needs_review') }}</strong>@endif</p>
        @if ($order->cancel_reason)<p class="text-danger">{{ __('orders::orders.cancelled_because', ['reason' => $order->cancel_reason]) }}</p>@endif
    </div>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    @error('transition')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror

    <section aria-labelledby="items-h" class="space-y-2">
        <h2 id="items-h" class="text-lg font-semibold">{{ __('orders::orders.items') }}</h2>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="border-b border-line">
                @foreach (['product', 'sku', 'qty', 'unit', 'discount', 'tax', 'total'] as $c)<th scope="col" class="py-2 pe-3 text-start">{{ __('orders::orders.item.'.$c) }}</th>@endforeach
            </tr></thead>
            <tbody>
            @foreach ($order->items as $item)
                <tr class="border-b border-line">
                    <td class="py-2 pe-3">{{ $item->name($locale) }}@if ($item->options_snapshot)<br><span class="text-muted">{{ collect($item->options_snapshot)->map(fn ($v, $k) => $k.': '.$v)->join(', ') }}</span>@endif</td>
                    <td class="py-2 pe-3">{{ $item->sku_snapshot }}</td>
                    <td class="py-2 pe-3">{{ \App\Modules\Platform\Support\Fmt::number($item->quantity) }}</td>
                    <td class="py-2 pe-3">{{ $fmt($item->unit_price_minor) }}</td>
                    <td class="py-2 pe-3">{{ $item->discount_minor > 0 ? '−'.$fmt($item->discount_minor) : '—' }}</td>
                    <td class="py-2 pe-3">{{ $item->tax_minor > 0 ? $fmt($item->tax_minor) : '—' }}</td>
                    <td class="py-2 pe-3 font-medium">{{ $fmt($item->line_total_minor) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        </div>
        <dl class="ms-auto max-w-sm space-y-1 text-sm">
            <div class="flex justify-between"><dt>{{ __('orders::orders.totals.subtotal') }}</dt><dd>{{ $fmt($order->subtotal_minor) }}</dd></div>
            @if ($order->discount_minor > 0)<div class="flex justify-between"><dt>{{ __('orders::orders.totals.discount') }}</dt><dd>−{{ $fmt($order->discount_minor) }}</dd></div>@endif
            <div class="flex justify-between"><dt>{{ __('orders::orders.totals.shipping') }}</dt><dd>{{ $fmt($order->shipping_minor) }}</dd></div>
            @if ($order->cod_fee_minor > 0)<div class="flex justify-between"><dt>{{ __('orders::orders.totals.cod_fee') }}</dt><dd>{{ $fmt($order->cod_fee_minor) }}</dd></div>@endif
            @if ($order->tax_minor > 0)<div class="flex justify-between"><dt>{{ $order->tax_inclusive ? __('orders::orders.totals.tax_included') : __('orders::orders.totals.tax_added') }}</dt><dd>{{ $fmt($order->tax_minor) }}</dd></div>@endif
            <div class="flex justify-between border-t border-line pt-1 text-base font-semibold"><dt>{{ __('orders::orders.totals.grand') }}</dt><dd>{{ $fmt($order->grand_total_minor) }}</dd></div>
        </dl>
    </section>

    <div class="grid gap-6 md:grid-cols-2">
        <section aria-labelledby="cust-h" class="space-y-1">
            <h2 id="cust-h" class="text-lg font-semibold">{{ __('orders::orders.customer') }}</h2>
            <p>{{ $order->contact_name }}@if (! $order->user_id) <span class="text-muted">({{ __('orders::orders.guest') }})</span>@endif</p>
            <p>{{ $phone($order->contact_phone) }}@if (! $canSeePhone) <span class="sr-only">{{ __('orders::orders.phone_hidden') }}</span>@endif</p>
            <p class="text-muted">{{ __('orders::orders.payment_method') }}: {{ $method }}</p>
        </section>
        @foreach ($order->addresses as $address)
            <section aria-labelledby="addr-{{ $address->type }}" class="space-y-1">
                <h2 id="addr-{{ $address->type }}" class="text-lg font-semibold">{{ __('orders::orders.'.$address->type.'_address') }}</h2>
                <p>{{ $address->recipient_name }} · {{ $phone($address->phone) }}</p>
                <p>{{ $address->address_line }}@if ($address->landmark), {{ $address->landmark }}@endif</p>
                <p>{{ collect([$address->area, $address->locationLine($locale), $address->postal_code])->filter()->join(', ') }}</p>
            </section>
        @endforeach
    </div>

    @can('orders.update')
    <section aria-labelledby="change-h" class="space-y-3">
        <h2 id="change-h" class="text-lg font-semibold">{{ __('orders::orders.change') }}</h2>
        <div class="grid gap-4 md:grid-cols-3">
        @foreach ($axes as $axis => [$current, $next])
            <form method="POST" action="{{ route('admin.orders.transition', $order) }}" class="space-y-2 rounded-lg border border-line p-4">
                @csrf
                <input type="hidden" name="axis" value="{{ $axis }}">
                <p class="font-medium">{{ __('orders::orders.axis.'.$axis) }}: {{ __('orders::orders.'.$axis.'.'.$current) }}</p>
                @php($options = array_values(array_filter($next, fn ($s) => $s->value !== 'cancelled' || $canCancel)))
                @if ($options === [])
                    <p class="text-sm text-muted">{{ __('orders::orders.no_moves') }}</p>
                @else
                    <label for="to-{{ $axis }}" class="block text-sm">{{ __('orders::orders.move_to') }}</label>
                    <select id="to-{{ $axis }}" name="to" class="block w-full rounded-md border border-line px-3 py-2">
                        @foreach ($options as $s)<option value="{{ $s->value }}">{{ $s->value === 'cancelled' ? __('orders::orders.cancel') : __('orders::orders.'.$axis.'.'.$s->value) }}</option>@endforeach
                    </select>
                    <label for="reason-{{ $axis }}" class="block text-sm">{{ __('orders::orders.reason') }} <span class="text-muted">({{ __('orders::orders.reason_hint') }})</span></label>
                    <input id="reason-{{ $axis }}" name="reason" maxlength="255" class="block w-full rounded-md border border-line px-3 py-2">
                    <x-platform::btn type="submit" size="sm">{{ __('orders::orders.apply') }}</x-platform::btn>
                @endif
            </form>
        @endforeach
        </div>
    </section>
    @endcan

    <section aria-labelledby="hist-h" class="space-y-2">
        <h2 id="hist-h" class="text-lg font-semibold">{{ __('orders::orders.history') }}</h2>
        <ol class="space-y-1 text-sm">
            @foreach ($order->history->reverse() as $h)
                <li>{{ \App\Modules\Platform\Support\Fmt::dateTime($h->created_at) }} — <strong>{{ __('orders::orders.axis.'.$h->axis) }}</strong>: @if ($h->from_state){{ __('orders::orders.'.($h->axis).'.'.$h->from_state) }} → @endif{{ __('orders::orders.'.($h->axis).'.'.$h->to_state) }}
                    <span class="text-muted">· {{ $h->actor_type === 'staff' ? __('orders::orders.staff') : ($h->actor_type === 'system' ? __('orders::orders.system') : $h->actor_type) }}@if ($h->reason) · {{ $h->reason === 'placed' ? __('orders::orders.placed') : $h->reason }}@endif</span></li>
            @endforeach
        </ol>
    </section>

    <section aria-labelledby="notes-h" class="space-y-3">
        <h2 id="notes-h" class="text-lg font-semibold">{{ __('orders::orders.notes') }}</h2>
        @forelse ($order->notes as $note)
            <div class="rounded-md border border-line p-3 text-sm"><p class="text-muted">{{ \App\Modules\Platform\Support\Fmt::dateTime($note->created_at) }} · {{ $note->visibility === 'customer' ? __('orders::orders.note_customer') : __('orders::orders.note_internal') }}</p><p class="whitespace-pre-line">{{ $note->body }}</p></div>
        @empty
            <p class="text-sm text-muted">{{ __('orders::orders.no_notes') }}</p>
        @endforelse
        @can('orders.update')
        <form method="POST" action="{{ route('admin.orders.note', $order) }}" class="space-y-2">
            @csrf
            <label for="note-body" class="block text-sm font-medium">{{ __('orders::orders.note_body') }}</label>
            <textarea id="note-body" name="body" rows="3" maxlength="2000" required class="block w-full rounded-md border border-line px-3 py-2"></textarea>
            @error('body')<p class="text-sm text-danger">{{ $message }}</p>@enderror
            <label for="note-vis" class="sr-only">{{ __('orders::orders.notes') }}</label>
            <select id="note-vis" name="visibility" class="rounded-md border border-line px-3 py-2"><option value="internal">{{ __('orders::orders.note_internal') }}</option><option value="customer">{{ __('orders::orders.note_customer') }}</option></select>
            <x-platform::btn type="submit" size="sm">{{ __('orders::orders.note_add') }}</x-platform::btn>
        </form>
        @endcan
    </section>
</div>
@endsection
