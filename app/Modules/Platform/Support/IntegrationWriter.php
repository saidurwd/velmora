<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Exceptions\SettingsException;
use Illuminate\Support\Facades\Cache;

/**
 * Saves one provider's credentials as one audited step. Never audits or logs a secret: a changed secret is recorded as "changed" or "removed",
 * nothing more (no value, no last four, no hash). Ordinary fields (a store id, a host) are recorded before and after.
 */
final class IntegrationWriter
{
    public function __construct(private readonly Settings $settings, private readonly IntegrationCredentials $credentials, private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, string>  $set  field => new value (blank secrets are not in here: they are left as they are)
     * @param  list<string>  $remove  fields to take back to the server's setting
     *
     * @throws SettingsException when the provider's settings changed since the form was opened
     */
    public function save(Actor $actor, string $provider, array $set, array $remove, string $version): void
    {
        $fields = IntegrationCatalogue::fields($provider);

        Cache::lock('integration-write:'.$provider, 10)->block(5, function () use ($actor, $provider, $set, $remove, $version, $fields): void {
            hash_equals($this->credentials->version($provider), $version) || throw SettingsException::conflict();

            $changes = [];
            foreach ($set as $field => $value) {
                $before = $this->credentials->get($provider, $field);
                $this->settings->set(IntegrationCatalogue::settingKey($provider, $field), $value, $fields[$field]['secret'], IntegrationCatalogue::SCOPE);
                $changes[$field] = $fields[$field]['secret'] ? ['changed' => true] : ['before' => $before, 'after' => $value];
            }
            foreach ($remove as $field) {
                $this->settings->forget(IntegrationCatalogue::settingKey($provider, $field), IntegrationCatalogue::SCOPE);
                $changes[$field] = ['removed' => true];
            }

            $this->audit->record('integrations.changed', 'integration', [], ['provider' => $provider, 'fields' => $changes], $actor, $provider);
        });
    }
}
