<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support\Api;

use Closure;

/**
 * What the app asks for once at start (GET /config). Platform adds the sections that are its own; other modules add theirs here
 * (Platform uses no other module). A section that fails to build is left out and logged, never taking the whole answer down.
 */
final class ApiConfig
{
    /** @var array<string, Closure(string): mixed> */
    private array $sections = [];

    /** @param  Closure(string): mixed  $build  receives the locale; must return JSON-safe data */
    public function section(string $key, Closure $build): void
    {
        $this->sections[$key] = $build;
    }

    /** @return array<string, mixed> */
    public function build(string $locale): array
    {
        $out = [];
        foreach ($this->sections as $key => $build) {
            try {
                $out[$key] = $build($locale);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $out;
    }
}
