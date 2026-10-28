<?php

declare(strict_types=1);

namespace Tests\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Enforces BD-11: a module may reference another module only through Contracts, DTOs, Enums and Events.
 * Platform is the shared kernel (every module may use it) but must not use any other module.
 */
final class ModuleBoundaryChecker
{
    public const ALLOWED_SHARED = ['Contracts', 'DTOs', 'Enums', 'Events'];

    /** @return list<array{file: string, line: int, reference: string}> */
    public function check(string $modulesPath): array
    {
        $violations = [];

        foreach (glob($modulesPath.'/*', GLOB_ONLYDIR) ?: [] as $moduleDir) {
            $module = basename($moduleDir);

            foreach ($this->phpFiles($moduleDir) as $file) {
                if (str_starts_with(substr($file, strlen($moduleDir) + 1), 'tests/')) {
                    continue;
                }

                foreach (file($file) ?: [] as $index => $line) {
                    if (! preg_match_all('/App\\\\Modules\\\\([A-Za-z0-9]+)\\\\([A-Za-z0-9]+)/', $line, $matches, PREG_SET_ORDER)) {
                        continue;
                    }

                    foreach ($matches as [$reference, $other, $layer]) {
                        if ($this->isViolation($module, $other, $layer)) {
                            $violations[] = ['file' => $file, 'line' => $index + 1, 'reference' => $reference];
                        }
                    }
                }
            }
        }

        return $violations;
    }

    private function isViolation(string $module, string $other, string $layer): bool
    {
        if ($other === $module) {
            return false;
        }

        if ($module === 'Platform') {
            return true; // the kernel depends on no other module
        }

        if ($other === 'Platform') {
            return false;
        }

        return ! in_array($layer, self::ALLOWED_SHARED, true);
    }

    /** @return list<string> */
    private function phpFiles(string $dir): array
    {
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
