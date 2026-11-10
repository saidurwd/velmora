<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Exceptions\SettingsException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Changes a group of settings as one audited step. The form carries a version (what the group looked like when it was opened); if somebody else
 * saved in between, nothing is written and the person is told. One writer per group at a time (a cache lock), so two saves cannot interleave.
 */
final class SettingsWriter
{
    public function __construct(private readonly Settings $settings, private readonly AuditLogger $audit, private readonly StorefrontCache $cache) {}

    /**
     * What this group of keys looks like right now: changes whenever any of them is saved.
     *
     * @param  list<string>  $keys
     */
    public function version(array $keys): string
    {
        $rows = DB::table('settings')->where('scope', 'app')->whereIn('key', $keys)->orderBy('key')->pluck('updated_at', 'key')->all();

        return substr(sha1(json_encode($rows, JSON_THROW_ON_ERROR)), 0, 16);
    }

    /**
     * @param  array<string, mixed>  $values  setting key => new value
     * @param  string|null  $version  the version the form was opened with; null = no check (a plain switch that sets a value, not edits a form)
     *
     * @throws SettingsException when the group changed since the form was opened
     */
    public function save(Actor $actor, string $auditAction, array $values, ?string $version): void
    {
        $keys = array_keys($values);

        Cache::lock('settings-write:'.sha1(implode('|', $keys)), 10)->block(5, function () use ($actor, $auditAction, $values, $version, $keys): void {
            $version === null || hash_equals($this->version($keys), $version) || throw SettingsException::conflict();

            $before = [];
            foreach ($keys as $key) {
                $before[$key] = $this->settings->get($key);
            }
            foreach ($values as $key => $value) {
                $this->settings->set($key, $value);
            }
            $this->audit->record($auditAction, 'settings', $before, $values, $actor);
        });

        $this->cache->bump();
    }
}
