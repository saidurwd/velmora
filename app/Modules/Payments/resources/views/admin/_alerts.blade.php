@if (! empty($alerts))
<section aria-labelledby="alerts-h" role="alert" class="space-y-2 rounded-lg border-2 border-danger p-4">
    <h2 id="alerts-h" class="text-lg font-semibold">{{ __('payments::admin.alerts_title') }}</h2>
    <ul class="list-disc ps-5">
        @foreach ($alerts as $name => $signal)
            <li>{{ __('payments::admin.alert.'.$name) }} — <span class="text-sm">{{ __('payments::admin.alert_value', ['value' => \App\Modules\Platform\Support\Fmt::number($signal['value']), 'threshold' => \App\Modules\Platform\Support\Fmt::number($signal['threshold'])]) }}</span></li>
        @endforeach
    </ul>
</section>
@endif
