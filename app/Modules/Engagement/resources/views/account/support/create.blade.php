@extends('layouts.account')

@section('title', __('engagement::support.new_title'))

@section('account')
<div class="max-w-xl space-y-6">
    <p><a class="underline" href="{{ route('account.support') }}">{{ __('engagement::support.back') }}</a></p>
    <h1 class="text-2xl font-bold">{{ __('engagement::support.new_title') }}</h1>
    <p class="text-sm text-muted">{{ __('engagement::support.new_help') }} <a class="underline" href="{{ route('help') }}">{{ __('engagement::support.help_link') }}</a></p>
    @error('support')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror

    <form method="POST" action="{{ route('account.support.store') }}" enctype="multipart/form-data" class="space-y-5" novalidate>
        @csrf
        <x-platform::form.field name="subject" :value="old('subject')" :label="__('engagement::support.subject_label')" maxlength="150" required />
        <div>
            <label for="category" class="block text-sm font-medium">{{ __('engagement::support.category_label') }}</label>
            <select id="category" name="category" required class="mt-1 block w-full rounded-md border border-line px-3 py-2">
                @foreach ($categories as $c)<option value="{{ $c }}" @selected(old('category', 'order') === $c)>{{ __('engagement::support.category.'.$c) }}</option>@endforeach
            </select>
            @error('category')<p class="mt-1 text-sm text-danger">{{ __('engagement::support.errors.invalid') }}</p>@enderror
        </div>
        <div>
            <label for="order" class="block text-sm font-medium">{{ __('engagement::support.order_label') }}</label>
            <select id="order" name="order" class="mt-1 block w-full rounded-md border border-line px-3 py-2">
                <option value="">{{ __('engagement::support.no_order') }}</option>
                @foreach ($orders as $o)<option value="{{ $o['public_id'] }}" @selected(old('order', $order) === $o['public_id'])>{{ $o['number'] }}</option>@endforeach
            </select>
        </div>
        <div>
            <label for="body" class="block text-sm font-medium">{{ __('engagement::support.body_label') }}</label>
            <textarea id="body" name="body" rows="7" minlength="5" maxlength="5000" required class="mt-1 block w-full rounded-md border border-line px-3 py-2">{{ old('body') }}</textarea>
            @error('body')<p class="mt-1 text-sm text-danger">{{ __('engagement::support.errors.too_short', ['min' => 5]) }}</p>@enderror
        </div>
        <div>
            <label for="files" class="block text-sm font-medium">{{ __('engagement::support.files_label') }}</label>
            <input id="files" name="files[]" type="file" multiple accept="image/jpeg,image/png,image/webp,application/pdf" class="mt-1 block w-full text-sm">
            @error('files')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
            @error('files.*')<p class="mt-1 text-sm text-danger">{{ __('engagement::support.errors.file_too_large', ['mb' => 5]) }}</p>@enderror
        </div>
        <x-platform::form.button>{{ __('engagement::support.send') }}</x-platform::form.button>
    </form>
</div>
@endsection
