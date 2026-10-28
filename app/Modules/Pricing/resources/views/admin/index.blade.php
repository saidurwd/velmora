@extends('layouts.admin')

@section('title', __('pricing::admin.title'))

@section('content')
<div class="space-y-6">
    <h1 class="text-2xl font-bold">{{ __('pricing::admin.title') }}</h1>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif

    <form method="GET" role="search" class="flex flex-wrap items-end gap-3">
        <div><label for="q" class="block text-sm font-medium">{{ __('pricing::admin.search') }}</label>
            <input id="q" name="q" value="{{ $term }}" class="block rounded-md border border-line px-3 py-2"></div>
        <button class="min-h-11 rounded-md border border-line px-5 py-2">{{ __('pricing::admin.find') }}</button>
    </form>

    @if ($term !== '')
        <table class="w-full text-sm">
            <caption class="sr-only">{{ __('pricing::admin.title') }}</caption>
            <thead><tr class="border-b border-line">
                <th scope="col" class="py-2 text-start">{{ __('pricing::admin.sku') }}</th>
                <th scope="col" class="py-2 text-start">{{ __('pricing::admin.product') }}</th>
                <th scope="col" class="py-2 text-end">{{ __('pricing::admin.price') }}</th>
            </tr></thead>
            <tbody>
            @forelse ($variants as $v)
                <tr class="border-b border-line">
                    <td class="py-2"><a class="underline" href="{{ route('admin.pricing.show', $v->sku) }}">{{ $v->sku }}</a></td>
                    <td class="py-2">{{ $v->productName }}</td>
                    <td class="py-2 text-end">{{ isset($prices[$v->id]) ? \App\Modules\Platform\Support\Fmt::money($prices[$v->id]['amount']) : __('pricing::admin.no_price') }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="py-6 text-center text-muted">{{ __('pricing::admin.none_found') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    @endif
</div>
@endsection
