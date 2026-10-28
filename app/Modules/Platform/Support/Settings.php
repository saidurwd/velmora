<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use App\Modules\Platform\Contracts\Clock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Typed key/value business settings with cache. Precedence: admin value (DB) > config('velmora.settings') > caller default.
 * Secrets are stored encrypted and never returned by listing helpers.
 */
final class Settings
{
    public function __construct(private readonly Cache $cache, private readonly Clock $clock) {}

    public function get(string $key, mixed $default = null, string $scope = 'app'): mixed
    {
        $row = $this->cache->rememberForever($this->cacheKey($scope, $key), function () use ($scope, $key): array {
            $found = DB::table('settings')->where('scope', $scope)->where('key', $key)->first();

            return $found === null ? ['exists' => false] : [
                'exists' => true,
                'secret' => (bool) $found->is_secret,
                'value' => $found->value === null ? null : json_decode((string) $found->value, true),
            ];
        });

        if (! $row['exists']) {
            /** @var array<string, mixed> $defaults dotted keys, so config('a.b') path syntax cannot be used */
            $defaults = config('velmora.settings', []);

            return array_key_exists($key, $defaults) ? $defaults[$key] : $default;
        }

        $value = $row['value'];

        return ($row['secret'] && is_string($value)) ? Crypt::decryptString($value) : $value;
    }

    public function bool(string $key, bool $default = false, string $scope = 'app'): bool
    {
        return filter_var($this->get($key, $default, $scope), FILTER_VALIDATE_BOOLEAN);
    }

    public function int(string $key, int $default = 0, string $scope = 'app'): int
    {
        return (int) $this->get($key, $default, $scope);
    }

    public function string(string $key, string $default = '', string $scope = 'app'): string
    {
        return (string) $this->get($key, $default, $scope);
    }

    public function set(string $key, mixed $value, bool $secret = false, string $scope = 'app'): void
    {
        $stored = ($secret && is_string($value)) ? Crypt::encryptString($value) : $value;
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');

        DB::table('settings')->updateOrInsert(
            ['scope' => $scope, 'key' => $key],
            ['value' => json_encode($stored, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'is_secret' => $secret, 'updated_at' => $now, 'created_at' => $now],
        );

        $this->cache->forget($this->cacheKey($scope, $key));
    }

    public function forget(string $key, string $scope = 'app'): void
    {
        DB::table('settings')->where('scope', $scope)->where('key', $key)->delete();
        $this->cache->forget($this->cacheKey($scope, $key));
    }

    private function cacheKey(string $scope, string $key): string
    {
        return "settings:{$scope}:{$key}";
    }
}
