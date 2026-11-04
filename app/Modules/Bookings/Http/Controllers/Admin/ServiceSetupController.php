<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Http\Controllers\Admin;

use App\Modules\Bookings\Actions\SaveServiceDefinition;
use App\Modules\Bookings\Actions\SaveServicePackage;
use App\Modules\Bookings\Actions\SetServiceOptions;
use App\Modules\Bookings\Exceptions\BookingException;
use App\Modules\Bookings\Http\Concerns\ActsAsStaff;
use App\Modules\Bookings\Http\Requests\SaveServiceRequest;
use App\Modules\Bookings\Services\SetupReader;
use App\Modules\Platform\Support\Transactions;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Staff make a service product bookable and set how long it takes, how far ahead it can be booked, which cancellation policy it follows and where an online one is joined. */
class ServiceSetupController
{
    use ActsAsStaff;

    public function __construct(private readonly SetupReader $reader) {}

    public function index(): View
    {
        return view('bookings::admin.setup.services', ['rows' => $this->reader->services(app()->getLocale())]);
    }

    public function edit(Request $request, string $sku): View
    {
        $data = $this->reader->service($sku, app()->getLocale()) ?? abort(404);

        return view('bookings::admin.setup.service-form', ['s' => $data, 'can' => $request->user('admin')?->can('bookings.manage') === true]);
    }

    public function save(SaveServiceRequest $request, SaveServiceDefinition $service, SaveServicePackage $package, SetServiceOptions $options, string $sku): RedirectResponse
    {
        $actor = $this->actor($request);
        try {
            Transactions::run(function () use ($request, $service, $package, $options, $actor, $sku): void {
                $service->handle($actor, $sku, (string) $request->validated('location'), (int) $request->validated('lead_time_min'), (int) $request->validated('max_horizon_days'), (int) $request->validated('hold_minutes'), $request->boolean('active'));
                $package->handle($actor, $sku, (int) $request->validated('duration_min'), (int) $request->validated('buffer_before_min'), (int) $request->validated('buffer_after_min'), $request->boolean('package_active', true));
                $options->handle($actor, $sku, $request->validated('policy'), $request->validated('meeting_url'));
            });
        } catch (BookingException $e) {
            return back()->withErrors(['setup' => __('bookings::errors.'.$e->reason, $e->params)])->withInput();
        }

        return redirect()->route('admin.bookings.setup.services.edit', ['sku' => $sku])->with('status', __('bookings::setup.saved'));
    }
}
