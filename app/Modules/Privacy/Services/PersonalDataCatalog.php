<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Services;

use App\Modules\Privacy\Contracts\PersonalDataEraser;
use App\Modules\Privacy\Contracts\PersonalDataExporter;
use App\Modules\Privacy\Contracts\PersonalDataRegistry;
use Illuminate\Contracts\Container\Container;

/** The list of who holds what, filled in by each module when it starts. */
final class PersonalDataCatalog implements PersonalDataRegistry
{
    /** @var list<class-string<PersonalDataExporter>> */
    private array $exporters = [];

    /** @var array<class-string<PersonalDataEraser>, int> */
    private array $erasers = [];

    public function __construct(private readonly Container $container) {}

    public function addExporter(string $class): void
    {
        if (! in_array($class, $this->exporters, true)) {
            $this->exporters[] = $class;
        }
    }

    public function addEraser(string $class, int $priority = 50): void
    {
        $this->erasers[$class] = $priority;
    }

    /** @return list<PersonalDataExporter> */
    public function exporters(): array
    {
        return array_map(fn (string $c): PersonalDataExporter => $this->container->make($c), $this->exporters);
    }

    /** @return list<PersonalDataEraser> in the order they must run */
    public function erasers(): array
    {
        $classes = array_keys($this->erasers);
        usort($classes, fn (string $a, string $b): int => [$this->erasers[$a], $a] <=> [$this->erasers[$b], $b]);

        return array_map(fn (string $c): PersonalDataEraser => $this->container->make($c), $classes);
    }
}
