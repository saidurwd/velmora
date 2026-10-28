<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/** Auto-registers every app/Modules/<Module>/Providers/*ServiceProvider.php. */
class ModulesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        foreach (glob(app_path('Modules/*/Providers/*ServiceProvider.php')) ?: [] as $file) {
            $module = basename(dirname($file, 2));
            $class = 'App\\Modules\\'.$module.'\\Providers\\'.basename($file, '.php');

            if (class_exists($class)) {
                $this->app->register($class);
            }
        }
    }
}
