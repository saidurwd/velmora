@extends('layouts.admin')

@section('title', $variant->sku)

@section('content')
<div class="max-w-4xl space-y-8">
    <div><a class="text-sm underline" href="{{ route('admin.digital.assets.index') }}">← {{ __('digital-products::admin.back') }}</a>
        <h1 class="mt-1 text-2xl font-bold">{{ $variant->sku }}</h1><p class="text-muted">{{ $variant->productName }}</p></div>
    @if (session('status'))<x-platform::alert type="success">{{ session('status') }}</x-platform::alert>@endif
    @error('asset')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror
    @error('file')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror

    @if ($can)
    <form method="POST" action="{{ route('admin.digital.assets.upload', $variant->sku) }}" enctype="multipart/form-data" class="space-y-3 rounded-lg border border-line p-4">@csrf
        <h2 class="text-lg font-semibold">{{ __('digital-products::admin.upload_title') }}</h2>
        <p class="text-sm text-muted">{{ __('digital-products::admin.upload_help', ['types' => $types, 'mb' => $max]) }}</p>
        <div><label for="file" class="block text-sm font-medium">{{ __('digital-products::admin.file') }}</label><input id="file" type="file" name="file" required class="block w-full text-sm"></div>
        <x-platform::btn type="submit" size="sm">{{ __('digital-products::admin.upload_btn') }}</x-platform::btn>
    </form>
    @endif

    <section aria-labelledby="p-h" class="space-y-2">
        <h2 id="p-h" class="text-lg font-semibold">{{ __('digital-products::admin.policies.for_product') }}</h2>
        <form method="POST" action="{{ route('admin.digital.assets.policy', $variant->sku) }}" class="flex flex-wrap items-end gap-3">@csrf
            <div><label for="policy" class="block text-sm font-medium">{{ __('digital-products::admin.policies.choose') }}</label>
                <select id="policy" name="policy" @disabled(! $can) class="mt-1 rounded-md border border-line px-3 py-2"><option value="">{{ __('digital-products::admin.policies.use_default') }}</option>@foreach ($policies as $p)<option value="{{ $p->public_id }}" @selected($chosenPolicy === $p->id)>{{ $p->words(app()->getLocale())['title'] ?? $p->code }}</option>@endforeach</select></div>
            @if ($can)<x-platform::btn type="submit" size="sm" variant="secondary">{{ __('digital-products::admin.policies.apply') }}</x-platform::btn>@endif
        </form>
    </section>

    <section aria-labelledby="k-h" class="space-y-3">
        <h2 id="k-h" class="text-lg font-semibold">{{ __('digital-products::admin.keys.title') }}</h2>
        <p class="text-sm text-muted">{{ __('digital-products::admin.keys.help', ['max' => \App\Modules\DigitalProducts\Actions\ImportLicenseKeys::MAX_LINES]) }}</p>
        @if ($keys['waiting'] > $keys['available'])<x-platform::alert type="error">{{ __('digital-products::admin.keys.short', ['waiting' => $keys['waiting'], 'available' => $keys['available']]) }}</x-platform::alert>@endif
        <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
            @foreach (['available', 'allocated', 'revoked', 'waiting'] as $k)<div class="rounded-md border border-line p-3"><dt class="text-muted">{{ __('digital-products::admin.keys.'.$k) }}</dt><dd class="text-xl font-semibold">{{ $keys[$k] }}</dd></div>@endforeach
        </dl>
        @if ($can)
        <form method="POST" action="{{ route('admin.digital.assets.keys.import', $variant->sku) }}" enctype="multipart/form-data" class="space-y-3 rounded-lg border border-line p-4">@csrf
            <div><label for="keys-file" class="block text-sm font-medium">{{ __('digital-products::admin.keys.file') }}</label><input id="keys-file" type="file" name="file" accept=".txt,.csv,text/plain,text/csv" required class="block w-full text-sm"></div>
            <x-platform::btn type="submit" size="sm">{{ __('digital-products::admin.keys.import_btn') }}</x-platform::btn>
        </form>
        @endif
    </section>

    <section aria-labelledby="v-h" class="space-y-2">
        <h2 id="v-h" class="text-lg font-semibold">{{ __('digital-products::admin.versions') }}</h2>
        @if ($versions->isEmpty())
            <p class="text-muted">{{ __('digital-products::admin.no_versions') }}</p>
        @else
        <div class="overflow-x-auto"><table class="w-full text-sm">
            <caption class="sr-only">{{ __('digital-products::admin.versions') }}</caption>
            <thead><tr class="border-b border-line">@foreach (['version', 'file', 'size', 'checksum', 'uploaded'] as $col)<th scope="col" class="py-2 pe-3 text-start">{{ __('digital-products::admin.cols.'.$col) }}</th>@endforeach</tr></thead>
            <tbody>
            @foreach ($versions as $v)
                <tr class="border-b border-line align-top"><td class="py-2 pe-3">{{ $v->version }}@if ($loop->first) <x-platform::badge tone="success">{{ __('digital-products::admin.current') }}</x-platform::badge>@endif</td>
                    <td class="py-2 pe-3">{{ $v->original_filename }}<br><span class="text-xs text-muted">{{ $v->mime }}</span></td><td class="py-2 pe-3">{{ __('digital-products::admin.size_kb', ['n' => \App\Modules\Platform\Support\Fmt::number(round($v->size_bytes / 1024))]) }}</td>
                    <td class="py-2 pe-3 break-all font-mono text-xs">{{ $v->sha256 }}</td><td class="py-2 pe-3">{{ \App\Modules\Platform\Support\Fmt::dateTime($v->created_at) }}</td></tr>
            @endforeach
            </tbody>
        </table></div>
        @endif
    </section>
</div>
@endsection
