<?php

use App\Http\Middleware\AuthenticateJwt;
use App\Http\Middleware\CanManageSanctions;
use App\Http\Middleware\CanProcessReplacements;
use App\Http\Middleware\CanViewSanctions;
use App\Http\Middleware\IsAdministrator;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(AuthenticateJwt::class);
        $middleware->alias([
            'role.admin' => IsAdministrator::class,
            'role.sanctions' => CanManageSanctions::class,
            'role.replacement' => CanProcessReplacements::class,
            'role.viewer' => CanViewSanctions::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
