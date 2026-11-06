<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Http\Controllers\Admin;

use App\Modules\Engagement\Http\Concerns\ActsAsStaff;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Settings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** How customers reach the shop by phone or email: shown on /contact (support.manage). */
class ContactSettingsController
{
    use ActsAsStaff;

    public function edit(Settings $settings): View
    {
        $hours = [];
        foreach ((array) config('app.supported_locales') as $locale) {
            $hours[$locale] = $settings->string("support.hours.{$locale}");
        }

        return view('engagement::admin.support.contact', ['phone' => $settings->string('support.contact_phone'), 'email' => $settings->string('support.contact_email'), 'hours' => $hours, 'responseHours' => $settings->int('support.first_response_hours', (int) config('engagement.support.first_response_hours', 24))]);
    }

    public function update(Request $request, Settings $settings, AuditLogger $audit): RedirectResponse
    {
        $rules = ['phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-() ]*$/'], 'email' => ['nullable', 'email:rfc', 'max:120'], 'response_hours' => ['required', 'integer', 'min:1', 'max:720']];
        foreach ((array) config('app.supported_locales') as $locale) {
            $rules["hours.{$locale}"] = ['nullable', 'string', 'max:120'];
        }
        $data = $request->validate($rules);

        $settings->set('support.contact_phone', trim((string) ($data['phone'] ?? '')));
        $settings->set('support.contact_email', trim((string) ($data['email'] ?? '')));
        $settings->set('support.first_response_hours', (int) $data['response_hours']);
        foreach ((array) config('app.supported_locales') as $locale) {
            $settings->set("support.hours.{$locale}", trim((string) ($data['hours'][$locale] ?? '')));
        }
        $audit->record('support.contact_saved', 'settings', [], ['response_hours' => (int) $data['response_hours']], $this->actor($request));

        return redirect()->route('admin.support.contact')->with('status', __('engagement::support.contact_saved'));
    }
}
