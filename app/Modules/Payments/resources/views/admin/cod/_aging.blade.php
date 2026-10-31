@php
    $fmtA = fn (int $minor) => \App\Modules\Platform\Support\Fmt::money(\App\Modules\Platform\DTOs\Money::of($minor, 'BDT'));
@endphp
<div class="grid gap-6 lg:grid-cols-2">
    @foreach (['uncollected', 'unremitted'] as $kind)
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <caption class="pb-2 text-start font-medium">{{ __('payments::admin.'.$kind) }}</caption>
                <thead><tr class="border-b border-line">
                    <th scope="col" class="py-2 pe-3 text-start">{{ __('payments::admin.age') }}</th>
                    <th scope="col" class="py-2 pe-3 text-start">{{ __('payments::admin.bucket_count') }}</th>
                    <th scope="col" class="py-2 pe-3 text-start">{{ __('payments::admin.bucket_amount') }}</th>
                </tr></thead>
                <tbody>
                @foreach ($aging[$kind] as $bucket => $row)
                    <tr class="border-b border-line"><th scope="row" class="py-2 pe-3 text-start font-normal">{{ $bucket }} {{ __('payments::admin.days') }}</th><td class="py-2 pe-3">{{ \App\Modules\Platform\Support\Fmt::number($row['count']) }}</td><td class="py-2 pe-3">{{ $fmtA($row['minor']) }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endforeach
</div>
