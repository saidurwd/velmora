<?php

declare(strict_types=1);

namespace App\Modules\Platform\Actions;

use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Exceptions\SettingsException;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\FeatureFlags;
use App\Modules\Platform\Support\Settings;
use App\Modules\Platform\Support\SettingsWriter;
use App\Modules\Platform\Support\StorefrontCache;

/** Turns a known feature flag on or off, or hands it back to the environment's default ($on = null). Only flags in the code's list exist. */
final class SetFeatureFlag
{
    public function __construct(private readonly SettingsWriter $writer, private readonly FeatureFlags $flags, private readonly Settings $settings, private readonly AuditLogger $audit, private readonly StorefrontCache $cache) {}

    /** @throws SettingsException */
    public function handle(Actor $actor, string $flag, ?bool $on): void
    {
        in_array($flag, FeatureFlags::names(), true) || throw SettingsException::unknown();

        $before = $this->flags->enabled($flag);
        if ($on === null) {
            $this->settings->forget(FeatureFlags::settingKey($flag));
            $this->audit->record('features.reset', 'feature', ['flag' => $flag, 'on' => $before], ['flag' => $flag, 'on' => $this->flags->enabled($flag)], $actor, $flag);
            $this->cache->bump();

            return;
        }

        $this->writer->save($actor, 'features.changed', [FeatureFlags::settingKey($flag) => $on], null);
    }
}
