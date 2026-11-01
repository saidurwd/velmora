<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Controllers\Web;

use App\Modules\Shipping\Services\OrderTracking;
use Illuminate\Contracts\View\View;

/** Where is my order? Reached only through a link WE signed (the confirmation page, the "my orders" page); the signature is the proof the customer holds the order. */
class TrackingController
{
    public function show(OrderTracking $tracking, string $order): View
    {
        $data = $tracking->for($order, app()->getLocale()) ?? abort(404);

        return view('shipping::track.show', ['track' => $data]);
    }
}
