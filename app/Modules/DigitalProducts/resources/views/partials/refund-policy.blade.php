@php $policies = app(\App\Modules\DigitalProducts\Contracts\RefundPolicyTexts::class)->forSkus($skus, app()->getLocale()); @endphp
@if ($policies !== [])
<section aria-labelledby="refund-h" class="space-y-2 rounded-lg border border-line p-4 text-sm">
    @foreach ($policies as $p)
        <h2 @if ($loop->first) id="refund-h" @endif class="font-semibold">{{ $p['title'] }}</h2>
        <p class="text-muted">{{ $p['text'] }}</p>
    @endforeach
</section>
@endif
