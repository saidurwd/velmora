<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Modules\Platform\Support\Branding;
use App\Modules\Platform\Support\MariaDbVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * What this installation is, for the About page: the release, the software it runs on, its settings in outline and the third-party parts it is built from.
 * Settings are named by kind only ("SMS: sslwireless", "Payments: sandbox"): never an address, key, password or token.
 */
final class AboutInfo
{
    public function __construct(private readonly Branding $branding) {}

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return [
            'shop' => $this->branding->name(),
            'release' => $this->release(),
            'platform' => [
                'environment' => app()->environment(),
                'debug' => (bool) config('app.debug'),
                'maintenance' => app()->isDownForMaintenance(),
                'laravel' => app()->version(),
                'php' => PHP_VERSION,
                'database' => $this->database(),
            ],
            'time' => [
                'stored' => 'UTC',
                'shown' => (string) config('app.display_timezone'),
                'now' => CarbonImmutable::now((string) config('app.display_timezone')),
            ],
            'languages' => ['supported' => (array) config('app.supported_locales'), 'default' => (string) config('app.default_locale')],
            'services' => [
                'cache' => (string) config('cache.default'),
                'session' => (string) config('session.driver'),
                'queue' => (string) config('queue.default').((bool) config('queue.run_from_scheduler') ? ' + scheduler' : ''),
                'mail' => (string) config('mail.default'),
                'media' => (string) config('filesystems.disks.media.driver'),
                'private' => (string) config('filesystems.disks.private.driver'),
                'sms' => (string) config('services.sms.driver'),
                'push' => (string) config('services.push.driver'),
                'payments' => $this->paymentsMode(),
            ],
            'modules' => $this->modules(),
            'components' => $this->components(),
        ];
    }

    /** @return array{id: string|null, built: CarbonImmutable|null} the release id written by deploy/cpanel/build-release.sh, if this is a built release */
    private function release(): array
    {
        $file = base_path('RELEASE');
        if (! is_file($file)) {
            return ['id' => null, 'built' => null];
        }
        $id = trim((string) file_get_contents($file));

        return ['id' => preg_match('/^[0-9a-f]{7,40}$/i', $id) === 1 ? substr($id, 0, 12) : null, 'built' => CarbonImmutable::createFromTimestamp((int) filemtime($file), 'UTC')];
    }

    private function database(): string
    {
        try {
            $reported = (string) DB::scalar('select version()');
            $parsed = MariaDbVersion::parse($reported);

            return $parsed === null ? $reported : ucfirst($parsed['engine'] === 'mariadb' ? 'MariaDB' : $parsed['engine']).' '.$parsed['version'];
        } catch (Throwable) {
            return '';
        }
    }

    /** live only when every switch says so (production, sandbox off, live allowed), matching the payments module's own rule */
    private function paymentsMode(): string
    {
        $live = app()->environment('production') && ! (bool) config('payments.sslcommerz.sandbox', true) && (bool) config('payments.allow_live');

        return $live ? 'live' : 'sandbox';
    }

    /** @return list<string> */
    private function modules(): array
    {
        $dirs = glob(app_path('Modules/*'), GLOB_ONLYDIR) ?: [];
        $names = array_map('basename', $dirs);
        sort($names);

        return $names;
    }

    /**
     * The third-party parts the code asks for directly (composer.json "require", package.json "dependencies"), with the installed version and licence.
     *
     * @return list<array{name: string, version: string, licence: string, kind: string}>
     */
    private function components(): array
    {
        $out = [];
        $composer = $this->json(base_path('composer.json'));
        $lock = $this->json(base_path('composer.lock'));
        $installed = [];
        foreach ((array) ($lock['packages'] ?? []) as $p) {
            $installed[(string) $p['name']] = ['version' => (string) ($p['version'] ?? ''), 'licence' => implode(', ', (array) ($p['license'] ?? []))];
        }
        foreach (array_keys((array) ($composer['require'] ?? [])) as $name) {
            if ($name === 'php' || str_starts_with($name, 'ext-')) {
                continue;
            }
            $out[] = ['name' => $name, 'version' => ltrim($installed[$name]['version'] ?? '', 'v'), 'licence' => $installed[$name]['licence'] ?? '', 'kind' => 'php'];
        }

        $package = $this->json(base_path('package.json'));
        $npmLock = $this->json(base_path('package-lock.json'));
        foreach (array_keys((array) ($package['dependencies'] ?? [])) as $name) {
            $p = (array) ($npmLock['packages']['node_modules/'.$name] ?? []);
            $out[] = ['name' => $name, 'version' => (string) ($p['version'] ?? ''), 'licence' => (string) ($p['license'] ?? ''), 'kind' => 'js'];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function json(string $file): array
    {
        if (! is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : [];
    }
}
