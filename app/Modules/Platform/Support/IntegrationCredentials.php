<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * What a provider's credentials are RIGHT NOW: the value staff saved in the admin area if there is one, otherwise the server's own setting.
 * A saved secret that can no longer be read (the app key changed) counts as not saved, so payments and mail fall back to the server instead of stopping.
 * Read on use, never at boot: the database may not be ready then.
 */
final class IntegrationCredentials
{
    public function __construct(private readonly Settings $settings) {}

    /** The value in force, or null when neither staff nor the server set it. */
    public function get(string $provider, string $field): ?string
    {
        $saved = $this->saved($provider, $field);
        if ($saved !== null) {
            return $saved;
        }

        $server = config(IntegrationCatalogue::fields($provider)[$field]['config']);

        return $server === null || $server === '' ? null : (string) $server;
    }

    /** saved = set here · server = from the server's setting · none = not set anywhere · unreadable = saved here but cannot be read any more */
    public function source(string $provider, string $field): string
    {
        $unreadable = false;
        if ($this->saved($provider, $field, $unreadable) !== null) {
            return 'saved';
        }
        if ($unreadable) {
            return 'unreadable';
        }

        return $this->get($provider, $field) === null ? 'none' : 'server';
    }

    /** What the screen may show: a secret as dots and its last four characters (only when long enough to hide the rest), anything else in full. */
    public function display(string $provider, string $field): string
    {
        $value = $this->get($provider, $field);
        if ($value === null) {
            return '';
        }
        if (! IntegrationCatalogue::fields($provider)[$field]['secret']) {
            return $value;
        }

        return mb_strlen($value) > 8 ? '••••••••'.mb_substr($value, -4) : '••••••••';
    }

    /** Where a field was last saved, for the optimistic-lock version (null when never saved here). */
    public function version(string $provider): string
    {
        $rows = DB::table('settings')->where('scope', IntegrationCatalogue::SCOPE)->whereIn('key', IntegrationCatalogue::keys($provider))->orderBy('key')->pluck('updated_at', 'key')->all();

        return substr(sha1(json_encode($rows, JSON_THROW_ON_ERROR)), 0, 16);
    }

    /**
     * SSLCommerz in the shape the Payments module's availability rules take. The sandbox switch always comes from the server.
     *
     * @return array{store_id: string|null, store_password: string|null, sandbox: bool}
     */
    public function sslcommerz(): array
    {
        return ['store_id' => $this->get('sslcommerz', 'store_id'), 'store_password' => $this->get('sslcommerz', 'store_password'), 'sandbox' => (bool) config('payments.sslcommerz.sandbox', true)];
    }

    /** Puts the saved mail values on the mail configuration. Called once, when Laravel first builds its mail manager. */
    public function applyMail(): void
    {
        foreach (array_keys(IntegrationCatalogue::fields('mail')) as $field) {
            $saved = $this->saved('mail', $field);
            $saved === null || config([IntegrationCatalogue::fields('mail')[$field]['config'] => $field === 'port' ? (int) $saved : $saved]);
        }
    }

    private function saved(string $provider, string $field, bool &$unreadable = false): ?string
    {
        try {
            $value = $this->settings->get(IntegrationCatalogue::settingKey($provider, $field), null, IntegrationCatalogue::SCOPE);
        } catch (DecryptException) {
            Log::warning('integrations.secret_unreadable', ['provider' => $provider, 'field' => $field]);   // no value, only which one
            $unreadable = true;

            return null;
        }

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
