<?php

use App\Http\Controllers\Api\LoginController;
use App\Http\Controllers\Api\ReposicionController;
use App\Http\Controllers\Api\SancionController;
use App\Http\Middleware\AuthorizeRestOperation;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/login', LoginController::class)->middleware('throttle:5,1');

    Route::post('/sanciones', [SancionController::class, 'store'])->middleware(AuthorizeRestOperation::class.':registrarSancion');
    Route::get('/solicitantes/{id}/sanciones', [SancionController::class, 'consultarPorSolicitante'])->whereNumber('id')->middleware(AuthorizeRestOperation::class.':consultarSancionesSolicitante');
    Route::post('/reposiciones', [ReposicionController::class, 'store'])->middleware(AuthorizeRestOperation::class.':registrarReposicion');
    Route::put('/reposiciones/{id}/completar', fn () => response()->json(['error' => 'Permisos insuficientes para ejecutar este método'], 403))->whereNumber('id')->middleware(AuthorizeRestOperation::class.':completarReposicion');

    Route::prefix('admin')->group(function (): void {
        Route::post('/sanciones', [SancionController::class, 'store'])->middleware('role.admin:registrarSancion');
        Route::get('/solicitantes/{id}/sanciones', [SancionController::class, 'consultarPorSolicitante'])->whereNumber('id')->middleware('role.admin:consultarSancionesSolicitante');
        Route::post('/reposiciones', [ReposicionController::class, 'store'])->middleware('role.admin:registrarReposicion');
        Route::get('/reposiciones/{id}', [ReposicionController::class, 'show'])->whereNumber('id')->middleware('role.admin:consultarReposicion');
    });

    Route::prefix('laboratorio')->group(function (): void {
        Route::post('/sanciones', [SancionController::class, 'store'])->middleware('role.sanctions:registrarSancion');
        Route::get('/solicitantes/{id}/sanciones', [SancionController::class, 'consultarPorSolicitante'])->whereNumber('id')->middleware('role.sanctions:consultarSancionesSolicitante');
    });

    Route::prefix('almacen')->group(function (): void {
        Route::post('/reposiciones', [ReposicionController::class, 'store'])->middleware('role.replacement:registrarReposicion');
        Route::get('/reposiciones/{id}', [ReposicionController::class, 'show'])->whereNumber('id')->middleware('role.replacement:consultarReposicion');
    });

    Route::prefix('solicitante')->group(function (): void {
        Route::get('/solicitantes/{id}/sanciones', [SancionController::class, 'consultarPorSolicitante'])->whereNumber('id')->middleware('role.viewer:consultarSancionesSolicitante');
        Route::get('/reposiciones/{id}', [ReposicionController::class, 'show'])->whereNumber('id')->middleware('role.viewer:consultarReposicion');
    });
});
