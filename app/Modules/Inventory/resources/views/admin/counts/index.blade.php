@extends('layouts.admin')

@section('title', __('inventory::screens.counts.title'))

@section('content')
<div class="space-y-8">
    <h1 class="text-2xl font-bold">{{ __('inventory::screens.counts.title') }}</h1>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    @error('count')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror
    @if (auth('admin')->user()?->can('inventory.count'))
    <form method="POST" action="{{ route('admin.inventory.counts.open') }}" class="space-y-3 rounded-lg border border-line p-4">@csrf
        <h2 class="text-lg font-semibold">{{ __('inventory::screens.counts.new') }}</h2><p class="text-sm text-muted">{{ __('inventory::screens.counts.help') }}</p>
        <div class="grid gap-3 sm:grid-cols-2">
            <div><label for="location" class="block text-sm font-medium">{{ __('inventory::screens.location') }}</label>
                <select id="location" name="location" required class="block w-full rounded-md border border-line px-3 py-2">@foreach ($locations as $p)<option value="{{ $p->code }}" @selected(old('location') === $p->code)>{{ $p->name }}</option>@endforeach</select></div>
            <div><label for="note" class="block text-sm font-medium">{{ __('inventory::screens.note') }}</label><input id="note" name="note" value="{{ old('note') }}" maxlength="255" class="block w-full rounded-md border border-line px-3 py-2"></div>
        </div>
        <div><label for="skus" class="block text-sm font-medium">{{ __('inventory::screens.counts.only') }}</label><textarea id="skus" name="skus" rows="2" maxlength="4000" class="block w-full rounded-md border border-line px-3 py-2">{{ old('skus') }}</textarea></div>
        <x-platform::btn type="submit" size="sm">{{ __('inventory::screens.counts.open_btn') }}</x-platform::btn>
    </form>
    @endif
    @if ($counts->isEmpty())
        <p class="text-muted">{{ __('inventory::screens.counts.empty') }}</p>
    @else
    <div class="overflow-x-auto"><table class="w-full text-sm">
        <caption class="sr-only">{{ __('inventory::screens.counts.title') }}</caption>
        <thead><tr class="border-b border-line">@foreach (['count', 'place', 'status', 'opened'] as $col)<th scope="col" class="py-2 pe-3 text-start">{{ __('inventory::screens.counts.cols.'.$col) }}</th>@endforeach</tr></thead>
        <tbody>
        @foreach ($counts as $c)
            <tr class="border-b border-line"><td class="py-2 pe-3"><a class="underline" href="{{ route('admin.inventory.counts.show', $c->public_id) }}">{{ strtoupper(substr($c->public_id, -8)) }}</a></td><td class="py-2 pe-3">{{ $places[$c->location_id] ?? '' }}</td>
                <td class="py-2 pe-3">{{ __('inventory::screens.counts.status.'.$c->status->value) }}</td><td class="py-2 pe-3">{{ \App\Modules\Platform\Support\Fmt::dateTime($c->created_at) }}</td></tr>
        @endforeach
        </tbody>
    </table></div>
    {{ $counts->links() }}
    @endif
</div>
@endsection
