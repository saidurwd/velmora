@extends('layouts.print')

@section('title', __('shipping::fulfilment.documents.pick.title'))

@php $pick = 'shipping::fulfilment.documents.pick.'; @endphp

@section('content')
<article class="mx-auto max-w-3xl space-y-6 p-6">
    <p class="print:hidden"><a class="text-sm underline" href="{{ $back }}">← {{ __('shipping::fulfilment.documents.back') }}</a></p>
    <header><h1 class="text-2xl font-bold">{{ __($pick.'title') }}</h1><p class="text-sm text-muted">{{ __($pick.'help') }}</p>
        <p class="text-sm">{{ __($pick.'parcels', ['count' => \App\Modules\Platform\Support\Fmt::number($parcels)]) }} · {{ \App\Modules\Platform\Support\Fmt::dateTime(now()) }}</p></header>
    @if ($capped)<x-platform::alert type="info">{{ __($pick.'capped', ['limit' => \App\Modules\Shipping\Http\Controllers\Admin\DocumentController::PICK_LIMIT]) }}</x-platform::alert>@endif
    @if ($products === [])
        <p class="text-muted">{{ __($pick.'empty') }}</p>
    @else
    <table class="w-full text-sm">
        <caption class="sr-only">{{ __($pick.'title') }}</caption>
        <thead><tr class="border-b-2 border-ink"><th scope="col" class="py-2 pe-3 text-start">{{ __($pick.'sku') }}</th><th scope="col" class="py-2 pe-3 text-start">{{ __($pick.'product') }}</th><th scope="col" class="py-2 pe-3 text-end">{{ __($pick.'qty') }}</th><th scope="col" class="py-2 pe-3 text-start">{{ __($pick.'for') }}</th><th scope="col" class="py-2 text-end">{{ __($pick.'picked') }}</th></tr></thead>
        <tbody>
        @foreach ($products as $sku => $p)
            <tr class="border-b border-line align-top"><td class="py-2 pe-3">{{ $sku }}</td><td class="py-2 pe-3">{{ $p['name'] }}</td><td class="py-2 pe-3 text-end font-bold">{{ \App\Modules\Platform\Support\Fmt::number($p['quantity']) }}</td>
                <td class="py-2 pe-3 text-xs">@foreach ($p['for'] as $f){{ $f['order'] }} / {{ $f['parcel'] }} × {{ $f['quantity'] }}@if (! $loop->last)<br>@endif @endforeach</td><td class="py-2 text-end" aria-hidden="true">☐</td></tr>
        @endforeach
        </tbody>
    </table>
    @endif
    <p class="print:hidden"><button type="button" data-print class="rounded-md border border-line px-4 py-2 text-sm hover:bg-surface-alt">{{ __('shipping::fulfilment.documents.print') }}</button></p>
</article>
@endsection
