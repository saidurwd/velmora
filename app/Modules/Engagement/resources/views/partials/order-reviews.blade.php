@inject('reviews', 'App\Modules\Engagement\Services\ReviewReader')
{{-- On a customer's order page: the items they can review now. --}}
@php $lines = $reviews->reviewable((int) auth('web')->id(), $order, app()->getLocale()); @endphp
@if ($lines !== [])
<section aria-labelledby="rev-h" class="space-y-2 rounded-lg border border-line p-4">
    <h2 id="rev-h" class="text-lg font-semibold">{{ __('engagement::reviews.order_title') }}</h2>
    <ul class="space-y-1">
        @foreach ($lines as $line)
            <li class="flex flex-wrap items-center justify-between gap-2">
                <span>{{ $line['name'] }}</span>
                @if ($line['reviewed'])
                    <a class="text-sm underline" href="{{ route('account.reviews') }}">{{ __('engagement::reviews.reviewed') }}</a>
                @else
                    <x-platform::btn :href="route('account.reviews.create', ['order' => $order, 'variant' => $line['variant']])" size="sm" variant="secondary">{{ __('engagement::reviews.write') }}<span class="sr-only"> — {{ $line['name'] }}</span></x-platform::btn>
                @endif
            </li>
        @endforeach
    </ul>
</section>
@endif
