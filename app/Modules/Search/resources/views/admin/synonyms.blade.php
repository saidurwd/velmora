@extends('layouts.admin')

@section('title', __('search::admin.synonyms'))

@section('content')
<div class="max-w-3xl space-y-6">
    <h1 class="text-2xl font-bold">{{ __('search::admin.synonyms') }}</h1>
    <p class="text-muted">{{ __('search::admin.synonyms_help') }}</p>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    <x-platform::form.errors />

    @can('catalog.update')
        <form method="POST" action="{{ route('admin.search.synonyms.store') }}" class="grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end" novalidate>
            @csrf
            <x-platform::form.field name="term" :label="__('search::admin.word')" />
            <x-platform::form.field name="synonym" :label="__('search::admin.same_as')" />
            <x-platform::form.button>{{ __('search::admin.add') }}</x-platform::form.button>
        </form>
    @endcan

    <form method="GET" role="search" class="flex items-end gap-3">
        <div><label for="q" class="block text-sm font-medium">{{ __('search::admin.filter') }}</label><input id="q" name="q" value="{{ $q }}" class="rounded-md border border-line px-3 py-2"></div>
        <x-platform::btn type="submit" variant="secondary">{{ __('search::admin.find') }}</x-platform::btn>
    </form>

    <table class="w-full text-sm">
        <caption class="sr-only">{{ __('search::admin.synonyms') }}</caption>
        <thead><tr class="border-b border-line">
            <th scope="col" class="py-2 text-start">{{ __('search::admin.word') }}</th>
            <th scope="col" class="py-2 text-start">{{ __('search::admin.same_as') }}</th>
            <th scope="col" class="py-2"><span class="sr-only">{{ __('search::admin.actions') }}</span></th>
        </tr></thead>
        <tbody>
        @forelse ($pairs as $pair)
            <tr class="border-b border-line">
                <td class="py-2">{{ $pair->term }}</td><td class="py-2">{{ $pair->synonym }}</td>
                <td class="py-2">@can('catalog.update')<form method="POST" action="{{ route('admin.search.synonyms.destroy', $pair->id) }}">@csrf @method('DELETE')<button class="underline">{{ __('search::admin.remove') }}</button></form>@endcan</td>
            </tr>
        @empty
            <tr><td colspan="3" class="py-6 text-center text-muted">{{ __('search::admin.empty') }}</td></tr>
        @endforelse
        </tbody>
    </table>
    {{ $pairs->links() }}
</div>
@endsection
