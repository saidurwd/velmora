<?php

declare(strict_types=1);

namespace App\Modules\Platform\Actions;

use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Exceptions\SettingsException;
use App\Modules\Platform\Support\AuditLogger;
use App\Modules\Platform\Support\StorefrontCache;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/**
 * Puts the storefront into maintenance and brings it back. The admin area and the health checks stay reachable (see bootstrap/app.php), so nobody
 * can lock themselves out; whoever turns it on gets a one-time link that lets them browse the storefront meanwhile. Audited both ways.
 */
final class MaintenanceSwitch
{
    public function __construct(private readonly AuditLogger $audit, private readonly StorefrontCache $cache) {}

    /**
     * @return string the secret of the bypass link, shown once
     *
     * @throws SettingsException
     */
    public function enable(Actor $actor): string
    {
        ! app()->isDownForMaintenance() || throw SettingsException::alreadyDown();

        $secret = Str::random(40);
        Artisan::call('down', ['--secret' => $secret, '--retry' => 60, '--render' => 'errors.503']);
        $this->audit->record('settings.maintenance_enabled', 'settings', [], ['maintenance' => true], $actor);
        $this->cache->bump();

        return $secret;
    }

    /** @throws SettingsException */
    public function disable(Actor $actor): void
    {
        app()->isDownForMaintenance() || throw SettingsException::notDown();

        Artisan::call('up');
        $this->audit->record('settings.maintenance_disabled', 'settings', ['maintenance' => true], ['maintenance' => false], $actor);
        $this->cache->bump();
    }
}
