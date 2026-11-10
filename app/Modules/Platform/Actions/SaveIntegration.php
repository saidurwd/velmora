<?php

declare(strict_types=1);

namespace App\Modules\Platform\Actions;

use App\Modules\Platform\Contracts\StaffReauthentication;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Exceptions\SettingsException;
use App\Modules\Platform\Support\BanglaText;
use App\Modules\Platform\Support\HostGuard;
use App\Modules\Platform\Support\IntegrationCatalogue;
use App\Modules\Platform\Support\IntegrationCredentials;
use App\Modules\Platform\Support\IntegrationWriter;

/**
 * Saves a provider's credentials. The staff member must type their own password again. A blank secret is left as it is; a blank ordinary field goes back
 * to the server's setting; an ordinary field that still equals the value in force is not written (so the server's values are never copied into the
 * database by a save). Only fields that changed are written and audited.
 */
final class SaveIntegration
{
    public function __construct(private readonly StaffReauthentication $reauth, private readonly IntegrationCredentials $credentials, private readonly IntegrationWriter $writer) {}

    /**
     * @param  array<string, mixed>  $input  field => value
     * @param  list<string>  $remove  secret fields to take back to the server's setting
     * @return list<string> the fields that changed
     *
     * @throws SettingsException
     */
    public function handle(Actor $actor, string $provider, array $input, array $remove, string $version, string $password): array
    {
        in_array($provider, IntegrationCatalogue::PROVIDERS, true) || throw SettingsException::unknown();
        ($actor->id !== null && $this->reauth->confirmPassword($actor->id, $password)) || throw SettingsException::password();

        $fields = IntegrationCatalogue::fields($provider);
        $set = [];
        $gone = [];

        foreach ($fields as $name => $def) {
            $raw = $input[$name] ?? null;
            $value = is_scalar($raw) ? BanglaText::input((string) $raw) : '';
            $def['type'] !== 'port' || $value = BanglaText::numeric($value);
            $saved = $this->credentials->source($provider, $name) === 'saved';

            if ($def['secret']) {
                if (in_array($name, $remove, true)) {
                    $saved && $gone[] = $name;
                } elseif ($value !== '') {
                    $set[$name] = $this->check($name, $def, $value);
                }

                continue;
            }

            if ($value === '') {
                $saved && $gone[] = $name;
            } elseif ($value !== (string) $this->credentials->get($provider, $name)) {
                $set[$name] = $this->check($name, $def, $value);
            }
        }

        $set === [] && $gone === [] || $this->writer->save($actor, $provider, $set, $gone, $version);

        return [...array_keys($set), ...$gone];
    }

    /** @param  array{secret: bool, type: string, max: int, choices?: list<string>}  $def */
    private function check(string $name, array $def, string $value): string
    {
        mb_strlen($value) <= $def['max'] || throw SettingsException::invalid($name, 'too_long');

        $ok = match ($def['type']) {
            'store_id' => preg_match('/^[A-Za-z0-9._@\-]{3,60}$/', $value) === 1,
            'password' => preg_match('/^[^\s\x00-\x1F\x7F]+$/u', $value) === 1,
            'sms_token' => preg_match('/^[A-Za-z0-9]{1,50}$/', $value) === 1,
            'sms_sid' => preg_match('/^[A-Za-z0-9]{1,20}$/', $value) === 1,
            'host' => preg_match('/^[A-Za-z0-9]([A-Za-z0-9.\-]*[A-Za-z0-9])?$/', $value) === 1,
            'port' => ctype_digit($value) && in_array((int) $value, IntegrationCatalogue::MAIL_PORTS, true),
            'choice' => in_array($value, $def['choices'] ?? [], true),
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            default => preg_match('/^[^\x00-\x1F\x7F]+$/u', $value) === 1,
        };
        $ok || throw SettingsException::invalid($name, $def['type'] === 'port' ? 'port_not_allowed' : 'invalid');
        $def['type'] !== 'host' || HostGuard::nameAllowed($value) || throw SettingsException::invalid($name, 'host_blocked');

        return $def['type'] === 'email' ? mb_strtolower($value) : $value;
    }
}
