<?php

use App\Http\Controllers\Api\LoginController;
use App\Http\Controllers\Api\ReposicionController;
use App\Http\Controllers\Api\SancionController;
use App\Http\Middleware\AuthenticateJwt;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/login', LoginController::class)->middleware('throttle:5,1');

    Route::middleware(AuthenticateJwt::class)->group(function () {
        // Sanciones
        Route::post('/sanciones', [SancionController::class, 'store']);
        Route::get('/solicitantes/{id}/sanciones', [SancionController::class, 'consultarPorSolicitante']);

        // Reposiciones
        Route::post('/reposiciones', [ReposicionController::class, 'store']);
        Route::put('/reposiciones/{id}/completar', [ReposicionController::class, 'completarReposicion']);
    });
});
