<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\Branding;
use App\Modules\Platform\Support\LogoProcessor;
use App\Modules\Platform\Support\Settings;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BrandingController
{
    public function edit(Branding $branding): View
    {
        return view('platform::admin.branding', ['branding' => $branding, 'locales' => (array) config('app.supported_locales')]);
    }

    public function update(Request $request, Settings $settings, Branding $branding, LogoProcessor $logos, AuditLogger $audit): RedirectResponse
    {
        $rules = [
            'name' => ['required', 'string', 'max:60'],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'logo' => ['nullable', 'file', 'max:1024'],
            'remove_logo' => ['sometimes', 'boolean'],
        ];
        foreach ((array) config('app.supported_locales') as $locale) {
            $rules["tagline.{$locale}"] = ['nullable', 'string', 'max:120'];
        }
        $data = $request->validate($rules);

        if (Branding::contrastWithWhite($data['color']) < Branding::MIN_CONTRAST) {
            throw ValidationException::withMessages(['color' => __('platform::branding.low_contrast')]);
        }

        $newLogo = null;
        if ($request->hasFile('logo')) {
            try {
                $png = $logos->process((string) file_get_contents((string) $request->file('logo')?->getRealPath()));
            } catch (DomainException $e) {
                throw ValidationException::withMessages(['logo' => __('platform::branding.'.$e->getMessage())]);
            }
            $newLogo = 'branding/logo-'.Str::lower((string) Str::ulid()).'.png';
            Storage::disk((string) config('catalog.media.disk', 'media'))->put($newLogo, $png, 'public');
        }

        $before = ['name' => $branding->name(), 'color' => $branding->color()];
        $settings->set('brand.name', trim($data['name']));
        $settings->set('brand.color', strtolower($data['color']));
        foreach ((array) config('app.supported_locales') as $locale) {
            $settings->set("brand.tagline.{$locale}", trim((string) ($data['tagline'][$locale] ?? '')));
        }

        $old = $settings->string('brand.logo');
        if ($newLogo !== null || $request->boolean('remove_logo')) {
            $settings->set('brand.logo', $newLogo ?? '');
            if ($old !== '') {
                Storage::disk((string) config('catalog.media.disk', 'media'))->delete($old);
            }
        }

        $audit->record('settings.branding_updated', 'settings', $before, ['name' => $data['name'], 'color' => $data['color'], 'logo_changed' => $newLogo !== null || $request->boolean('remove_logo')]);

        return redirect()->route('admin.settings.branding')->with('status', __('platform::branding.saved'));
    }
}
