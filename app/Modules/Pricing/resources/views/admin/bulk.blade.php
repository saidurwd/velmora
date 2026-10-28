@extends('layouts.admin')

@section('title', __('pricing::admin.bulk.title'))

@section('content')
<div class="max-w-4xl space-y-6">
    <h1 class="text-2xl font-bold">{{ __('pricing::admin.bulk.title') }}</h1>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    <x-platform::form.errors />

    <form method="POST" action="{{ route('admin.pricing.bulk.preview') }}" class="space-y-4" novalidate>
        @csrf
        <div class="space-y-1">
            <label for="skus" class="block text-sm font-medium">{{ __('pricing::admin.bulk.skus') }}</label>
            <textarea id="skus" name="skus" rows="5" class="block w-full rounded-md border border-line px-3 py-2 font-mono">{{ old('skus', implode("\n", $skus)) }}</textarea>
        </div>
        <x-platform::form.field name="percent" inputmode="decimal" :value="$percent ?? ''" :label="__('pricing::admin.bulk.percent')" :help="__('pricing::admin.bulk.percent_help')" />
        <div class="space-y-1">
            <label for="round" class="block text-sm font-medium">{{ __('pricing::admin.bulk.round') }}</label>
            <select id="round" name="round" class="block rounded-md border border-line px-3 py-2">
                @foreach ([1 => 'round_none', 100 => 'round_1', 500 => 'round_5'] as $value => $key)<option value="{{ $value }}" @selected((int) old('round', $round ?? 1) === $value)>{{ __('pricing::admin.bulk.'.$key) }}</option>@endforeach
            </select>
        </div>
        <x-platform::form.button>{{ __('pricing::admin.bulk.preview') }}</x-platform::form.button>
    </form>

    @if ($preview)
        <section aria-labelledby="pv-h" class="space-y-3">
            <h2 id="pv-h" class="text-lg font-semibold">{{ __('pricing::admin.bulk.preview') }}</h2>
            @if ($preview->isEmpty())
                <p class="text-muted">{{ __('pricing::admin.bulk.nothing') }}</p>
            @else
                <table class="w-full text-sm">
                    <caption class="sr-only">{{ __('pricing::admin.bulk.preview') }}</caption>
                    <thead><tr class="border-b border-line">
                        <th scope="col" class="py-2 text-start">{{ __('pricing::admin.sku') }}</th>
                        <th scope="col" class="py-2 text-end">{{ __('pricing::admin.bulk.old') }}</th>
                        <th scope="col" class="py-2 text-end">{{ __('pricing::admin.bulk.new') }}</th>
                        <th scope="col" class="py-2 text-start">{{ __('pricing::admin.bulk.warning') }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($preview->rows as $row)
                        <tr class="border-b border-line">
                            <td class="py-2">{{ $summaries[$row['variant_id']]->sku ?? $row['variant_id'] }}</td>
                            <td class="py-2 text-end">{{ \App\Modules\Platform\Support\Fmt::money(\App\Modules\Platform\DTOs\Money::of($row['old_minor'])) }}</td>
                            <td class="py-2 text-end">{{ \App\Modules\Platform\Support\Fmt::money(\App\Modules\Platform\DTOs\Money::of($row['new_minor'])) }}</td>
                            <td class="py-2 text-warning">{{ $row['warning'] ? __('pricing::admin.bulk.warnings.'.\Illuminate\Support\Str::slug($row['warning'], '_')) : '' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                <form method="POST" action="{{ route('admin.pricing.bulk.apply') }}">
                    @csrf
                    <input type="hidden" name="skus" value="{{ implode("\n", $skus) }}">
                    <input type="hidden" name="percent" value="{{ $percent }}">
                    <input type="hidden" name="round" value="{{ $round }}">
                    <input type="hidden" name="fingerprint" value="{{ $preview->fingerprint }}">
                    <x-platform::form.button>{{ __('pricing::admin.bulk.apply', ['count' => count($preview->rows)]) }}</x-platform::form.button>
                </form>
            @endif
        </section>
    @endif
</div>
@endsection
