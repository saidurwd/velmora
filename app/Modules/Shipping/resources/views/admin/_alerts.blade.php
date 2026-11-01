@if (! empty($alerts))
<section aria-labelledby="alerts-h" role="alert" class="space-y-2 rounded-lg border-2 border-danger p-4">
    <h2 id="alerts-h" class="text-lg font-semibold">{{ __('shipping::fulfilment.alerts_title') }}</h2>
    <ul class="list-disc ps-5">
        @foreach ($alerts as $name => $signal)
            <li>{{ __('shipping::fulfilment.alert.'.$name) }} — <span class="text-sm">{{ \App\Modules\Platform\Support\Fmt::number($signal['value']) }}</span></li>
        @endforeach
    </ul>
</section>
@endif
