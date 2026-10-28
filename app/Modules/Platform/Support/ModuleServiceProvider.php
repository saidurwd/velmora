<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

/**
 * Base provider for every module under app/Modules/<Module> (BD-10).
 * Wires migrations, views, translations and the three route files (web, admin, api).
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    /** Module directory name, e.g. "Orders". */
    abstract protected function module(): string;

    public function boot(): void
    {
        $base = $this->basePath();
        $slug = Str::kebab($this->module());

        $this->loadMigrationsFrom($base.'/Database/migrations');

        if (is_dir($base.'/resources/views')) {
            $this->loadViewsFrom($base.'/resources/views', $slug);
            Blade::anonymousComponentPath($base.'/resources/views/components', $slug); // <x-slug::name />
        }

        if (is_dir($base.'/resources/lang')) {
            $this->loadTranslationsFrom($base.'/resources/lang', $slug);
            $this->loadJsonTranslationsFrom($base.'/resources/lang');
        }

        if ($this->app->routesAreCached()) {
            return;
        }

        $this->routeFile('web', fn (string $file) => Route::middleware('web')->group($file));
        $this->routeFile('admin', fn (string $file) => Route::middleware('web')->prefix('admin')->name('admin.')->group($file));
        $this->routeFile('api', fn (string $file) => Route::middleware('api')->prefix('api/v1')->name('api.v1.')->group($file));
    }

    protected function basePath(): string
    {
        return app_path('Modules/'.$this->module());
    }

    /** @param  callable(string): mixed  $register */
    private function routeFile(string $name, callable $register): void
    {
        $file = $this->basePath().'/routes/'.$name.'.php';

        if (is_file($file)) {
            $register($file);
        }
    }
}
