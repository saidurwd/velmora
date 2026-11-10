<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Modules\Platform\Actions\SaveIntegration;
use App\Modules\Platform\Actions\TestIntegration;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Exceptions\SettingsException;
use App\Modules\Platform\Support\IntegrationCatalogue;
use App\Modules\Platform\Support\IntegrationCredentials;
use App\Modules\Platform\Support\IntegrationTests;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Provider credentials. Everything here needs integrations.manage (sensitive). Secrets are never sent to the browser; changing anything asks for the staff member's own password. */
class IntegrationsController
{
    public function index(IntegrationCredentials $credentials, IntegrationTests $tests): View
    {
        $providers = [];
        foreach (IntegrationCatalogue::PROVIDERS as $provider) {
            $fields = [];
            foreach (IntegrationCatalogue::fields($provider) as $name => $def) {
                $fields[$name] = ['secret' => $def['secret'], 'type' => $def['type'], 'choices' => $def['choices'] ?? null, 'source' => $credentials->source($provider, $name), 'display' => $credentials->display($provider, $name),
                    'value' => $def['secret'] ? '' : (string) $credentials->get($provider, $name)];
            }
            $providers[$provider] = ['fields' => $fields, 'version' => $credentials->version($provider), 'canTest' => $tests->has($provider)];
        }

        return view('platform::admin.settings.integrations', [
            'providers' => $providers,
            'server' => [
                'sandbox' => (bool) config('payments.sslcommerz.sandbox', true), 'live_allowed' => (bool) config('payments.allow_live'), 'environment' => app()->environment(),
                'sms_driver' => (string) config('services.sms.driver', 'log'), 'mailer' => (string) config('mail.default'),
            ],
        ]);
    }

    public function update(Request $request, SaveIntegration $save, string $provider): RedirectResponse
    {
        $data = $request->validate(['version' => ['required', 'string', 'size:16'], 'password' => ['required', 'string', 'max:200'], 'f' => ['nullable', 'array'], 'remove' => ['nullable', 'array']]);

        try {
            $changed = $save->handle($this->actor($request), $provider, (array) ($data['f'] ?? []), array_keys(array_filter((array) ($data['remove'] ?? []))), $data['version'], $data['password']);
        } catch (SettingsException $e) {
            $key = trans()->has('platform::integrations.errors.'.$e->reason) ? 'platform::integrations.errors.'.$e->reason : 'platform::settings.errors.'.$e->reason;

            return redirect()->route('admin.integrations.index')->withInput($request->except(['password', 'f.store_password', 'f.api_token', 'f.password']))->withErrors([($e->field === null || $e->field === 'password' ? 'integration' : 'f.'.$e->field) => __($key)]);
        }

        return redirect()->route('admin.integrations.index')->with('status', $changed === [] ? __('platform::integrations.nothing_changed') : __('platform::integrations.saved'));
    }

    public function test(Request $request, TestIntegration $test, string $provider): RedirectResponse
    {
        try {
            $outcome = $test->handle($this->actor($request), $provider);
        } catch (SettingsException) {
            abort(404);
        }

        return redirect()->route('admin.integrations.index')->with($outcome->ok ? 'test_ok' : 'test_bad', __('platform::integrations.tests.'.$provider.'_'.$outcome->code))->with('tested', $provider);
    }

    private function actor(Request $request): Actor
    {
        $user = $request->user('admin');
        abort_if($user === null, 403);

        return new Actor(ActorType::Staff, (int) $user->getAuthIdentifier(), $user->public_id, (string) $user->name);
    }
}
