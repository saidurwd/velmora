<!doctype html>
<html lang="{{ $order->locale }}">
<body style="font-family: system-ui, sans-serif; line-height: 1.5; color: #111;">
<h1 style="font-size: 20px;">{{ __('orders::notifications.email_heading', ['number' => $order->order_number]) }}</h1>
<p>{{ __('orders::notifications.email_intro', ['name' => $order->contact_name]) }}</p>
<table role="presentation" cellpadding="6" style="border-collapse: collapse; width: 100%; max-width: 480px;">
    @foreach ($order->items as $item)
        <tr><td style="border-bottom: 1px solid #ddd;">{{ $item->name($order->locale) }} × {{ $item->quantity }}</td><td align="right" style="border-bottom: 1px solid #ddd;">{{ $money($item->line_total_minor) }}</td></tr>
    @endforeach
    <tr><td>{{ __('orders::checkout.delivery') }}</td><td align="right">{{ $order->shipping_minor === 0 ? __('orders::checkout.free') : $money($order->shipping_minor) }}</td></tr>
    @if ($order->cod_fee_minor > 0)<tr><td>{{ __('orders::checkout.cod_fee') }}</td><td align="right">{{ $money($order->cod_fee_minor) }}</td></tr>@endif
    <tr><td><strong>{{ __('orders::checkout.total') }}</strong></td><td align="right"><strong>{{ $money($order->grand_total_minor) }}</strong></td></tr>
</table>
@if ($order->payment_method_code === 'cod')<p>{{ __('orders::checkout.cod_message', ['total' => $money($order->grand_total_minor)]) }}</p>@endif
<p><a href="{{ $link }}">{{ __('orders::notifications.email_link') }}</a></p>
<p style="color: #555; font-size: 13px;">{{ __('orders::notifications.email_footer') }}</p>
</body>
</html>
