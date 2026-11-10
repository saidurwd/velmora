<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Modules\Platform\Actions\SaveSettingsGroup;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Exceptions\SettingsException;
use App\Modules\Platform\Support\BusinessProfile;
use App\Modules\Platform\Support\Settings;
use App\Modules\Platform\Support\SettingsGroups;
use App\Modules\Platform\Support\SettingsWriter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** The settings screens: a list, the business profile, the tax switches and the (read-only) currency and language. Looking = settings.view, saving = settings.manage. */
class SettingsController
{
    private const BUSINESS_TEXTS = ['legal_name', 'address', 'invoice_footer'];

    public function index(): View
    {
        return view('platform::admin.settings.index');
    }

    public function business(BusinessProfile $profile, SettingsWriter $writer): View
    {
        $texts = [];
        foreach ((array) config('app.supported_locales') as $locale) {
            $texts[] = (object) ['locale' => $locale, 'legal_name' => $profile->localValue('legal_name', $locale), 'address' => $profile->localValue('address', $locale), 'invoice_footer' => $profile->localValue('invoice_footer', $locale)];
        }

        return view('platform::admin.settings.business', ['texts' => collect($texts), 'profile' => $profile, 'version' => $writer->version(SettingsGroups::keys('business'))]);
    }

    public function updateBusiness(Request $request, SaveSettingsGroup $save): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'string', 'size:16'], 'phone' => ['nullable', 'string', 'max:40'], 'email' => ['nullable', 'string', 'max:120'], 'bin' => ['nullable', 'string', 'max:20'], 'tin' => ['nullable', 'string', 'max:20'], 'texts' => ['nullable', 'array']]);

        $input = ['phone' => $data['phone'] ?? null, 'email' => $data['email'] ?? null, 'bin' => $data['bin'] ?? null, 'tin' => $data['tin'] ?? null];
        foreach ((array) config('app.supported_locales') as $locale) {
            foreach (self::BUSINESS_TEXTS as $field) {
                $input["{$field}_{$locale}"] = $data['texts'][$locale][$field] ?? null;
            }
        }

        return $this->persist($request, $save, 'business', $input, $data['version'], 'admin.settings.business');
    }

    public function tax(Settings $settings, SettingsWriter $writer): View
    {
        $values = [];
        foreach (SettingsGroups::fields('tax') as $name => $def) {
            $values[$name] = $settings->bool($def['key']);
        }

        return view('platform::admin.settings.tax', ['values' => $values, 'version' => $writer->version(SettingsGroups::keys('tax'))]);
    }

    public function updateTax(Request $request, SaveSettingsGroup $save): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'string', 'size:16']]);
        $input = [];
        foreach (array_keys(SettingsGroups::fields('tax')) as $name) {
            $input[$name] = $request->boolean($name);
        }

        return $this->persist($request, $save, 'tax', $input, $data['version'], 'admin.settings.tax');
    }

    public function locale(): View
    {
        return view('platform::admin.settings.locale', ['currency' => (string) config('velmora.settings')['business.currency'], 'default' => (string) config('app.default_locale'), 'supported' => (array) config('app.supported_locales'), 'labels' => config('velmora.locale_labels')]);
    }

    /** @param  array<string, mixed>  $input */
    private function persist(Request $request, SaveSettingsGroup $save, string $group, array $input, string $version, string $route): RedirectResponse
    {
        $user = $request->user('admin');
        abort_if($user === null, 403);

        try {
            $save->handle(new Actor(ActorType::Staff, (int) $user->getAuthIdentifier(), $user->public_id, (string) $user->name), $group, $input, $version);
        } catch (SettingsException $e) {
            return back()->withInput()->withErrors([$e->field ?? 'settings' => __('platform::settings.errors.'.$e->reason, $e->params)]);
        }

        return redirect()->route($route)->with('status', __('platform::settings.saved'));
    }
}
