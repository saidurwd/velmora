@inject('portal', 'App\Modules\Shipping\Services\ReturnPortal')
{{-- On a customer's order page: send goods back, when something can still be returned. --}}
@php $lines = $portal->lines((int) auth('web')->id(), $order, app()->getLocale()) ?? []; $open = collect($lines)->contains(fn ($l) => $l['open']); @endphp
@if ($open)
<section aria-labelledby="ret-h" class="space-y-2 rounded-lg border border-line p-4">
    <h2 id="ret-h" class="text-lg font-semibold">{{ __('shipping::returns.order_title') }}</h2>
    <p class="text-sm text-muted">{{ __('shipping::returns.order_help') }}</p>
    <p><x-platform::btn :href="route('account.returns.create', $order)" variant="secondary">{{ __('shipping::returns.order_button') }}</x-platform::btn> <a class="ms-2 text-sm underline" href="{{ route('account.returns') }}">{{ __('shipping::returns.mine_title') }}</a></p>
</section>
@endif
