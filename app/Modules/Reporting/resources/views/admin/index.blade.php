@extends('layouts.admin')

@section('title', __('reporting::reports.index_title'))

@section('content')
<div class="max-w-4xl space-y-6">
    <h1 class="text-2xl font-bold">{{ __('reporting::reports.index_title') }}</h1>
    <p class="text-sm text-muted">{{ __('reporting::reports.index_intro') }}</p>
    <ul class="space-y-3">
        <li class="rounded-lg border border-line p-4">
            <a class="text-lg font-semibold underline" href="{{ route('admin.reports.sales') }}">{{ __('reporting::reports.title') }}</a>
            <p class="text-sm text-muted">{{ __('reporting::reports.sales_description') }}</p>
        </li>
        @foreach ($reports as $report)
            <li class="rounded-lg border border-line p-4">
                <a class="text-lg font-semibold underline" href="{{ route('admin.reports.show', ['code' => $report->code()]) }}">{{ __('reporting::catalogue.'.$report->code().'.title') }}</a>
                <p class="text-sm text-muted">{{ __('reporting::catalogue.'.$report->code().'.description') }}</p>
            </li>
        @endforeach
    </ul>
    @if ($canExport)<p><a class="underline" href="{{ route('admin.reports.exports') }}">{{ __('reporting::exports.menu') }}</a></p>@endif
</div>
@endsection
