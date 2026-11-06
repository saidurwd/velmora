<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Http\Controllers\Web;

use App\Modules\Platform\Support\Settings;
use Illuminate\Contracts\View\View;

/** How to reach the shop, for everyone (guests included): the phone and email staff entered, and where to find the help centre. Nothing on this page is customer data. */
class ContactController
{
    public function show(Settings $settings): View
    {
        $locale = app()->getLocale();
        $hours = $settings->string("support.hours.{$locale}");

        return view('engagement::contact', [
            'phone' => $settings->string('support.contact_phone'), 'email' => $settings->string('support.contact_email'),
            'hours' => $hours !== '' ? $hours : $settings->string('support.hours.'.config('app.default_locale')),
        ]);
    }
}
