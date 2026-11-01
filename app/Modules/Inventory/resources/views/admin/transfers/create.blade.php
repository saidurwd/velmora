@extends('layouts.admin')

@section('title', __('inventory::screens.transfers.new'))

@section('content')
<div class="max-w-3xl space-y-6">
    <div><a class="text-sm underline" href="{{ route('admin.inventory.transfers.index') }}">{{ __('inventory::screens.back') }}</a><h1 class="mt-1 text-2xl font-bold">{{ __('inventory::screens.transfers.new') }}</h1></div>
    @error('transfer')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror
    <form method="POST" action="{{ route('admin.inventory.transfers.store') }}" class="space-y-4">
        @csrf
        <p class="text-sm text-muted">{{ __('inventory::screens.transfers.help') }}</p>
        <div class="grid gap-3 sm:grid-cols-2">
            <div><label for="from" class="block text-sm font-medium">{{ __('inventory::screens.transfers.cols.from') }}</label>
                <select id="from" name="from" required class="block w-full rounded-md border border-line px-3 py-2">@foreach ($places as $p)<option value="{{ $p->code }}" @selected(old('from') === $p->code)>{{ $p->name }}</option>@endforeach</select></div>
            <div><label for="to" class="block text-sm font-medium">{{ __('inventory::screens.transfers.cols.to') }}</label>
                <select id="to" name="to" required class="block w-full rounded-md border border-line px-3 py-2">@foreach ($places as $p)<option value="{{ $p->code }}" @selected(old('to') === $p->code)>{{ $p->name }}</option>@endforeach</select></div>
        </div>
        <fieldset class="space-y-2"><legend class="text-sm font-medium">{{ __('inventory::screens.transfers.items') }}</legend>
            @for ($i = 0; $i < $rows; $i++)
            <div class="grid grid-cols-3 gap-3">
                <div class="col-span-2"><label for="sku-{{ $i }}" class="sr-only">{{ __('inventory::screens.sku') }} {{ $i + 1 }}</label><input id="sku-{{ $i }}" name="lines[{{ $i }}][sku]" value="{{ old('lines.'.$i.'.sku') }}" placeholder="{{ __('inventory::screens.sku') }}" maxlength="64" class="block w-full rounded-md border border-line px-3 py-2"></div>
                <div><label for="qty-{{ $i }}" class="sr-only">{{ __('inventory::screens.quantity') }} {{ $i + 1 }}</label><input id="qty-{{ $i }}" type="number" min="1" name="lines[{{ $i }}][qty]" value="{{ old('lines.'.$i.'.qty') }}" placeholder="{{ __('inventory::screens.quantity') }}" class="block w-full rounded-md border border-line px-3 py-2"></div>
            </div>
            @endfor
        </fieldset>
        <div><label for="note" class="block text-sm font-medium">{{ __('inventory::screens.note') }}</label><input id="note" name="note" value="{{ old('note') }}" maxlength="255" class="block w-full rounded-md border border-line px-3 py-2"></div>
        <x-platform::btn type="submit" size="sm">{{ __('inventory::screens.transfers.send') }}</x-platform::btn>
    </form>
</div>
@endsection
