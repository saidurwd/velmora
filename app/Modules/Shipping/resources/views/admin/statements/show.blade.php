@extends('layouts.admin')

@section('title', __('shipping::fulfilment.statements.show_title', ['number' => $statement->statement_ref]))

@php
    $fmt = fn (int $minor, string $cur = 'BDT') => \App\Modules\Platform\Support\Fmt::money(\App\Modules\Platform\DTOs\Money::of($minor, $cur));
@endphp

@section('content')
<div class="space-y-6">
    <div><a class="text-sm underline" href="{{ route('admin.shipping.statements.index') }}">{{ __('shipping::fulfilment.back') }}</a>
        <h1 class="mt-1 text-2xl font-bold">{{ __('shipping::fulfilment.statements.show_title', ['number' => $statement->statement_ref]) }}</h1></div>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    <p class="text-sm">{{ $statement->courier }} · {{ __('shipping::fulfilment.statements.cols.received') }}: {{ $fmt($statement->received_minor, $statement->currency) }} · {{ __('shipping::fulfilment.statements.cols.fee') }}: {{ $fmt($statement->fee_minor, $statement->currency) }} · {{ __('shipping::fulfilment.statements.cols.problems') }}: {{ $statement->problem_count }}</p>
    <div class="overflow-x-auto"><table class="w-full text-sm">
        <caption class="sr-only">{{ __('shipping::fulfilment.statements.show_title', ['number' => $statement->statement_ref]) }}</caption>
        <thead><tr class="border-b border-line">@foreach (['consignment', 'status', 'cash', 'fee', 'delivered', 'result'] as $col)<th scope="col" class="py-2 pe-3 text-start">{{ __('shipping::fulfilment.statements.line_cols.'.$col) }}</th>@endforeach</tr></thead>
        <tbody>
        @foreach ($lines as $l)
            <tr class="border-b border-line"><td class="py-2 pe-3">{{ $l->consignment_id }}</td><td class="py-2 pe-3">{{ $l->courier_status }}</td><td class="py-2 pe-3">{{ $fmt((int) $l->cod_minor, $statement->currency) }}</td><td class="py-2 pe-3">{{ $fmt((int) $l->fee_minor, $statement->currency) }}</td>
                <td class="py-2 pe-3">{{ $l->delivered_at ? \App\Modules\Platform\Support\Fmt::dateTime(\Carbon\CarbonImmutable::parse($l->delivered_at, 'UTC')) : __('shipping::fulfilment.none') }}</td>
                <td class="py-2 pe-3"><x-platform::badge :tone="$l->result === 'matched' ? 'success' : 'danger'">{{ __('shipping::fulfilment.statements.result.'.$l->result) }}</x-platform::badge></td></tr>
        @endforeach
        </tbody>
    </table></div>
</div>
@endsection
