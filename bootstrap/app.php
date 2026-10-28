<?php

declare(strict_types=1);

use App\Modules\Platform\Http\ExceptionRenderer;
use App\Modules\Platform\Http\Middleware\AssignRequestContext;
use App\Modules\Platform\Http\Middleware\SecureHeaders;
use App\Modules\Platform\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: env('TRUSTED_PROXIES') ? explode(',', (string) env('TRUSTED_PROXIES')) : null);
        $middleware->append(AssignRequestContext::class);
        $middleware->append(SecureHeaders::class);
        $middleware->appendToGroup('web', SetLocale::class);
        $middleware->appendToGroup('api', SetLocale::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        ExceptionRenderer::register($exceptions);
    })->create();
