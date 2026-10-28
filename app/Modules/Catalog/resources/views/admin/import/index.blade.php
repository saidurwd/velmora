@extends('layouts.admin')

@section('title', __('catalog::admin.import.title'))

@section('content')
<div class="max-w-4xl space-y-8">
    <h1 class="text-2xl font-bold">{{ __('catalog::admin.import.title') }}</h1>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    <x-platform::form.errors />

    <section aria-labelledby="exp-h" class="space-y-2">
        <h2 id="exp-h" class="text-lg font-semibold">{{ __('catalog::admin.import.export') }}</h2>
        <p class="text-muted">{{ __('catalog::admin.import.export_help') }}</p>
        <a class="underline" href="{{ route('admin.catalog.import.export') }}">{{ __('catalog::admin.import.download_all') }}</a>
        · <a class="underline" href="{{ route('admin.catalog.import.template') }}">{{ __('catalog::admin.import.download_template') }}</a>
    </section>

    @can('catalog.create')
        <section aria-labelledby="imp-h" class="space-y-3">
            <h2 id="imp-h" class="text-lg font-semibold">{{ __('catalog::admin.import.upload') }}</h2>
            <p class="text-muted">{{ __('catalog::admin.import.rules') }}</p>
            <form method="POST" action="{{ route('admin.catalog.import.store') }}" enctype="multipart/form-data" class="space-y-3 rounded-md border border-line p-4">
                @csrf
                <div class="space-y-1">
                    <label for="file" class="block text-sm font-medium">{{ __('catalog::admin.import.file') }}</label>
                    <input id="file" name="file" type="file" accept=".csv,text/csv" required class="block w-full">
                </div>
                <label class="flex items-center gap-2"><input type="checkbox" name="dry_run" value="1" checked class="size-4"> {{ __('catalog::admin.import.dry_run') }}</label>
                <x-platform::form.button>{{ __('catalog::admin.import.start') }}</x-platform::form.button>
            </form>
        </section>
    @endcan

    <section aria-labelledby="recent-h" class="space-y-2">
        <h2 id="recent-h" class="text-lg font-semibold">{{ __('catalog::admin.import.recent') }}</h2>
        <table class="w-full text-sm">
            <caption class="sr-only">{{ __('catalog::admin.import.recent') }}</caption>
            <thead><tr class="border-b border-line">
                <th scope="col" class="py-2 text-start">{{ __('catalog::admin.import.file') }}</th>
                <th scope="col" class="py-2 text-start">{{ __('catalog::admin.import.mode') }}</th>
                <th scope="col" class="py-2 text-start">{{ __('catalog::admin.status') }}</th>
                <th scope="col" class="py-2 text-start">{{ __('catalog::admin.import.when') }}</th>
            </tr></thead>
            <tbody>
            @forelse ($imports as $import)
                <tr class="border-b border-line">
                    <td class="py-2"><a class="underline" href="{{ route('admin.catalog.import.show', $import) }}">{{ $import->filename }}</a></td>
                    <td class="py-2">{{ $import->dry_run ? __('catalog::admin.import.mode_check') : __('catalog::admin.import.mode_apply') }}</td>
                    <td class="py-2">{{ __('catalog::admin.import.statuses.'.$import->status) }}</td>
                    <td class="py-2">{{ \App\Modules\Platform\Support\Fmt::dateTime($import->created_at) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="py-6 text-center text-muted">{{ __('catalog::admin.empty') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </section>
</div>
@endsection
