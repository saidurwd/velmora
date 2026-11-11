@extends('layouts.admin')

@section('title', __('inventory::imports.title'))

@section('content')
<div class="space-y-8">
    <h1 class="text-2xl font-bold">{{ __('inventory::imports.title') }}</h1>
    <p class="max-w-3xl text-muted">{{ __('inventory::imports.intro') }}</p>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    @error('import')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror
    @foreach (['file', 'location', 'mode', 'note'] as $field)@error($field)<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror @endforeach
    <form method="POST" action="{{ route('admin.inventory.imports.store') }}" enctype="multipart/form-data" class="space-y-3 rounded-lg border border-line p-4">@csrf
        <h2 class="text-lg font-semibold">{{ __('inventory::imports.new') }}</h2>
        <div class="grid gap-3 sm:grid-cols-2">
            <div><label for="file" class="block text-sm font-medium">{{ __('inventory::imports.file') }}</label>
                <input id="file" type="file" name="file" accept=".csv,text/csv" required aria-describedby="file-help" class="block w-full rounded-md border border-line px-3 py-2">
                <p id="file-help" class="mt-1 text-xs text-muted">{{ __('inventory::imports.file_help', ['max' => \App\Modules\Platform\Support\Fmt::number($max)]) }}</p></div>
            <div><label for="location" class="block text-sm font-medium">{{ __('inventory::imports.location') }}</label>
                <select id="location" name="location" required class="block w-full rounded-md border border-line px-3 py-2">@foreach ($locations as $p)<option value="{{ $p->code }}" @selected(old('location') === $p->code)>{{ $p->name }}</option>@endforeach</select></div>
            <div><label for="mode" class="block text-sm font-medium">{{ __('inventory::imports.mode') }}</label>
                <select id="mode" name="mode" required class="block w-full rounded-md border border-line px-3 py-2">@foreach (['set', 'add'] as $m)<option value="{{ $m }}" @selected(old('mode') === $m)>{{ __('inventory::imports.mode_'.$m) }}</option>@endforeach</select></div>
            <div><label for="note" class="block text-sm font-medium">{{ __('inventory::imports.note') }}</label>
                <input id="note" name="note" value="{{ old('note') }}" maxlength="255" required class="block w-full rounded-md border border-line px-3 py-2"></div>
        </div>
        <x-platform::btn type="submit" size="sm">{{ __('inventory::imports.read') }}</x-platform::btn>
    </form>
    @if ($imports->isEmpty())
        <p class="text-muted">{{ __('inventory::imports.none') }}</p>
    @else
    <div class="overflow-x-auto"><table class="w-full text-sm">
        <caption class="sr-only">{{ __('inventory::imports.title') }}</caption>
        <thead><tr class="border-b border-line">@foreach (['file', 'place', 'rows', 'problems', 'status', 'read'] as $col)<th scope="col" class="py-2 pe-3 text-start">{{ __('inventory::imports.cols.'.$col) }}</th>@endforeach</tr></thead>
        <tbody>
        @foreach ($imports as $i)
            <tr class="border-b border-line">
                <td class="py-2 pe-3"><a class="underline" href="{{ route('admin.inventory.imports.show', $i->public_id) }}">{{ $i->file_name ?: strtoupper(substr($i->public_id, -8)) }}</a></td>
                <td class="py-2 pe-3">{{ $places[$i->location_id] ?? '' }}</td>
                <td class="py-2 pe-3">{{ \App\Modules\Platform\Support\Fmt::number($i->row_count) }}</td>
                <td class="py-2 pe-3">{{ \App\Modules\Platform\Support\Fmt::number($i->error_count) }}</td>
                <td class="py-2 pe-3">{{ __('inventory::imports.status.'.$i->status) }}</td>
                <td class="py-2 pe-3">{{ \App\Modules\Platform\Support\Fmt::dateTime(\Carbon\CarbonImmutable::parse($i->created_at, 'UTC')) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    {{ $imports->links() }}
    @endif
</div>
@endsection
