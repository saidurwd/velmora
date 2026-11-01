@extends('layouts.admin')

@section('title', __('inventory::screens.transfers.title'))

@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between"><h1 class="text-2xl font-bold">{{ __('inventory::screens.transfers.title') }}</h1>
        @if (auth('admin')->user()?->can('inventory.transfer'))
            <x-platform::btn :href="route('admin.inventory.transfers.create')" size="sm">{{ __('inventory::screens.transfers.new') }}</x-platform::btn>
        @endif
    </div>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    @if ($transfers->isEmpty())
        <p class="text-muted">{{ __('inventory::screens.transfers.empty') }}</p>
    @else
    <div class="overflow-x-auto"><table class="w-full text-sm">
        <caption class="sr-only">{{ __('inventory::screens.transfers.title') }}</caption>
        <thead><tr class="border-b border-line">@foreach (['transfer', 'from', 'to', 'status', 'made'] as $col)<th scope="col" class="py-2 pe-3 text-start">{{ __('inventory::screens.transfers.cols.'.$col) }}</th>@endforeach</tr></thead>
        <tbody>
        @foreach ($transfers as $t)
            <tr class="border-b border-line"><td class="py-2 pe-3"><a class="underline" href="{{ route('admin.inventory.transfers.show', $t->public_id) }}">{{ strtoupper(substr($t->public_id, -8)) }}</a></td>
                <td class="py-2 pe-3">{{ $places[$t->from_location_id] ?? '' }}</td><td class="py-2 pe-3">{{ $places[$t->to_location_id] ?? '' }}</td>
                <td class="py-2 pe-3">{{ __('inventory::screens.transfers.status.'.$t->status->value) }}</td><td class="py-2 pe-3">{{ \App\Modules\Platform\Support\Fmt::dateTime($t->created_at) }}</td></tr>
        @endforeach
        </tbody>
    </table></div>
    {{ $transfers->links() }}
    @endif
</div>
@endsection
