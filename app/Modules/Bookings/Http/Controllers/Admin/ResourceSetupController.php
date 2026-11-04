<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Http\Controllers\Admin;

use App\Modules\Bookings\Actions\SaveHoliday;
use App\Modules\Bookings\Actions\SaveServiceResource;
use App\Modules\Bookings\Actions\SetAvailabilityException;
use App\Modules\Bookings\Actions\SetWeeklyAvailability;
use App\Modules\Bookings\Exceptions\BookingException;
use App\Modules\Bookings\Http\Concerns\ActsAsStaff;
use App\Modules\Bookings\Http\Requests\AvailabilityExceptionRequest;
use App\Modules\Bookings\Http\Requests\HolidayRequest;
use App\Modules\Bookings\Http\Requests\SaveResourceRequest;
use App\Modules\Bookings\Http\Requests\WeeklyAvailabilityRequest;
use App\Modules\Bookings\Models\ServiceResource;
use App\Modules\Bookings\Services\SetupReader;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Staff: the people and rooms that can be booked, the hours each one works, their days off and one-off changes, and the shop's holidays. */
class ResourceSetupController
{
    use ActsAsStaff;

    public function __construct(private readonly SetupReader $reader) {}

    public function index(): View
    {
        return view('bookings::admin.setup.resources', ['rows' => $this->reader->resources()]);
    }

    public function create(): View
    {
        return view('bookings::admin.setup.resource-form', ['r' => null, 'services' => $this->reader->bookableSkus()]);
    }

    public function store(SaveResourceRequest $request, SaveServiceResource $save): RedirectResponse
    {
        try {
            $resource = $save->handle($this->actor($request), null, (string) $request->validated('name'), (string) $request->validated('type'), $request->boolean('active', true), array_values((array) $request->validated('services', [])));
        } catch (BookingException $e) {
            return back()->withErrors(['setup' => __('bookings::errors.'.$e->reason, $e->params)])->withInput();
        }

        return redirect()->route('admin.bookings.setup.resources.edit', ['resource' => $resource->public_id])->with('status', __('bookings::setup.saved'));
    }

    public function edit(Request $request, string $resource): View
    {
        $data = $this->reader->resource($resource) ?? abort(404);

        return view('bookings::admin.setup.resource-form', ['r' => $data, 'services' => $data['services'], 'can' => $request->user('admin')?->can('bookings.manage') === true]);
    }

    public function update(SaveResourceRequest $request, SaveServiceResource $save, string $resource): RedirectResponse
    {
        $model = ServiceResource::query()->where('public_id', $resource)->firstOrFail();
        try {
            $save->handle($this->actor($request), $model, (string) $request->validated('name'), (string) $request->validated('type'), $request->boolean('active'), array_values((array) $request->validated('services', [])));
        } catch (BookingException $e) {
            return back()->withErrors(['setup' => __('bookings::errors.'.$e->reason, $e->params)])->withInput();
        }

        return redirect()->route('admin.bookings.setup.resources.edit', ['resource' => $resource])->with('status', __('bookings::setup.saved'));
    }

    public function weekly(WeeklyAvailabilityRequest $request, SetWeeklyAvailability $set, string $resource): RedirectResponse
    {
        $model = ServiceResource::query()->where('public_id', $resource)->firstOrFail();
        $windows = [];
        foreach ((array) $request->validated('rules', []) as $row) {
            $start = trim((string) ($row['start'] ?? ''));
            $end = trim((string) ($row['end'] ?? ''));
            if ($start === '' && $end === '') {
                continue;   // an empty row is just an empty row
            }
            $windows[] = ['weekday' => (int) ($row['weekday'] ?? 0), 'start' => $start, 'end' => $end, 'valid_from' => ($row['valid_from'] ?? '') === '' ? null : $row['valid_from'], 'valid_to' => ($row['valid_to'] ?? '') === '' ? null : $row['valid_to']];
        }

        return $this->done($resource, fn () => $set->handle($this->actor($request), $model, $windows));
    }

    public function exception(AvailabilityExceptionRequest $request, SetAvailabilityException $set, string $resource): RedirectResponse
    {
        $model = ServiceResource::query()->where('public_id', $resource)->firstOrFail();

        return $this->done($resource, fn () => $set->set($this->actor($request), $model, (string) $request->validated('date'), $request->boolean('closed'), $request->validated('start'), $request->validated('end'), $request->validated('note')));
    }

    public function removeException(Request $request, SetAvailabilityException $set, string $resource, string $date): RedirectResponse
    {
        $model = ServiceResource::query()->where('public_id', $resource)->firstOrFail();

        return $this->done($resource, fn () => $set->remove($this->actor($request), $model, $date));
    }

    public function holidays(): View
    {
        return view('bookings::admin.setup.holidays', ['rows' => $this->reader->holidays()]);
    }

    public function addHoliday(HolidayRequest $request, SaveHoliday $save): RedirectResponse
    {
        try {
            $save->set($this->actor($request), (string) $request->validated('date'), (string) $request->validated('name'));
        } catch (BookingException $e) {
            return back()->withErrors(['setup' => __('bookings::errors.'.$e->reason, $e->params)])->withInput();
        }

        return redirect()->route('admin.bookings.setup.holidays')->with('status', __('bookings::setup.saved'));
    }

    public function removeHoliday(Request $request, SaveHoliday $save, string $date): RedirectResponse
    {
        try {
            $save->remove($this->actor($request), $date);
        } catch (BookingException $e) {
            return back()->withErrors(['setup' => __('bookings::errors.'.$e->reason, $e->params)]);
        }

        return redirect()->route('admin.bookings.setup.holidays')->with('status', __('bookings::setup.removed'));
    }

    private function done(string $resource, callable $do): RedirectResponse
    {
        try {
            $do();
        } catch (BookingException $e) {
            return back()->withErrors(['setup' => __('bookings::errors.'.$e->reason, $e->params)])->withInput();
        }

        return redirect()->route('admin.bookings.setup.resources.edit', ['resource' => $resource])->with('status', __('bookings::setup.saved'));
    }
}
