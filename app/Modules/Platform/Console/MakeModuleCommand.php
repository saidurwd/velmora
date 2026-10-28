<?php

declare(strict_types=1);

namespace App\Modules\Platform\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/** php artisan make:module Orders — creates the BD-10 skeleton. The provider is auto-discovered. */
class MakeModuleCommand extends Command
{
    protected $signature = 'make:module {name : StudlyCase module name, e.g. Orders} {--path= : Override the modules directory (tests)}';

    protected $description = 'Create a new module skeleton under app/Modules';

    private const DIRECTORIES = [
        'Actions', 'Contracts', 'DTOs', 'Enums', 'Events', 'Listeners', 'Jobs', 'Policies', 'Exceptions', 'Models',
        'Http/Controllers/Web', 'Http/Controllers/Admin', 'Http/Controllers/Api', 'Http/Requests', 'Http/Resources',
        'Providers', 'routes', 'resources/views', 'resources/lang/en', 'resources/lang/bn',
        'database/migrations', 'tests/Unit', 'tests/Feature',
    ];

    public function handle(Filesystem $files): int
    {
        $name = (string) $this->argument('name');

        if (! preg_match('/^[A-Z][A-Za-z0-9]+$/', $name)) {
            $this->error('Module name must be StudlyCase letters/digits, e.g. "Orders".');

            return self::FAILURE;
        }

        $root = (string) ($this->option('path') ?: app_path('Modules'));
        $base = $root.'/'.$name;

        if ($files->isDirectory($base)) {
            $this->error("Module [{$name}] already exists.");

            return self::FAILURE;
        }

        foreach (self::DIRECTORIES as $dir) {
            $files->makeDirectory($base.'/'.$dir, 0755, true);
            $files->put($base.'/'.$dir.'/.gitkeep', '');
        }

        $files->put($base.'/Providers/'.$name.'ServiceProvider.php', $this->provider($name));

        foreach (['web', 'admin', 'api'] as $routes) {
            $files->put($base.'/routes/'.$routes.'.php', "<?php\n\ndeclare(strict_types=1);\n\nuse Illuminate\\Support\\Facades\\Route;\n");
        }

        $files->put($base.'/README.md', "# {$name} module\n\nResponsibilities, owned tables, events emitted/consumed: see docs/architecture/DOMAIN_MODEL_AND_MODULE_BOUNDARIES.md.\nCross-module use is limited to Contracts, DTOs, Enums and Events (BD-11).\n");

        $this->info("Module [{$name}] created at {$base} (slug: ".Str::kebab($name).').');

        return self::SUCCESS;
    }

    private function provider(string $name): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace App\\Modules\\{$name}\\Providers;

        use App\\Modules\\Platform\\Support\\ModuleServiceProvider;

        class {$name}ServiceProvider extends ModuleServiceProvider
        {
            protected function module(): string
            {
                return '{$name}';
            }
        }

        PHP;
    }
}
