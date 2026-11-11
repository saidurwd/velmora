@extends('layouts.admin')

@section('title', __('platform::bulk.confirm_title'))

@section('content')
@php $known = array_values(array_filter($rows, fn ($r) => $r['label'] !== null)); @endphp
<div class="max-w-3xl space-y-6">
    <h1 class="text-2xl font-bold">{{ __('platform::bulk.confirm_title') }}</h1>
    <p>{{ __('platform::bulk.confirm_intro', ['action' => __($action->label()), 'count' => \App\Modules\Platform\Support\Fmt::number(count($known))]) }}</p>
    @if (count($rows) !== count($known))<x-platform::alert type="warning">{{ __('platform::bulk.some_missing', ['count' => \App\Modules\Platform\Support\Fmt::number(count($rows) - count($known))]) }}</x-platform::alert>@endif
    @if ($errors->any())<x-platform::alert type="error">{{ $errors->first() }}</x-platform::alert>@endif
    <ul class="list-disc space-y-1 ps-6">
        @foreach ($known as $r)<li>{{ $r['label'] }}</li>@endforeach
    </ul>
    @if ($known === [])
        <p class="text-muted">{{ __('platform::bulk.nothing_to_do') }}</p>
    @else
    <form method="POST" action="{{ route('admin.bulk.run', $action->code()) }}" class="space-y-4">@csrf
        @foreach ($known as $r)<input type="hidden" name="ids[]" value="{{ $r['id'] }}">@endforeach
        @foreach ($fields as $f)
            <div><label for="p-{{ $f['name'] }}" class="block text-sm font-medium">{{ __($f['label']) }}</label>
                @if ($f['type'] === 'select')
                    <select id="p-{{ $f['name'] }}" name="p[{{ $f['name'] }}]" @required($f['required']) class="mt-1 block w-full max-w-sm rounded-md border border-line px-3 py-2">
                        @foreach ($f['options'] ?? [] as $value => $label)<option value="{{ $value }}" @selected(old('p.'.$f['name']) === (string) $value)>{{ $label }}</option>@endforeach
                    </select>
                @else
                    <input id="p-{{ $f['name'] }}" name="p[{{ $f['name'] }}]" value="{{ old('p.'.$f['name']) }}" maxlength="{{ $f['max'] ?? 255 }}" @required($f['required']) class="mt-1 block w-full rounded-md border border-line px-3 py-2">
                @endif
            </div>
        @endforeach
        <p class="text-sm text-muted">{{ __('platform::bulk.each_one_alone', ['max' => \App\Modules\Platform\Support\Fmt::number($max)]) }}</p>
        <div class="flex gap-3">
            <x-platform::btn type="submit">{{ __('platform::bulk.run') }}</x-platform::btn>
            <a href="{{ url()->previous() }}" class="self-center underline">{{ __('platform::bulk.back') }}</a>
        </div>
    </form>
    @endif
</div>
@endsection
