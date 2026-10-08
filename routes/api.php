<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\SancionController;
use App\Http\Controllers\Api\ReposicionController;

Route::prefix('v1')->group(function () {
    // Sanciones
    Route::post('/sanciones', [SancionController::class, 'store']);
    Route::get('/solicitantes/{id}/sanciones', [SancionController::class, 'consultarPorSolicitante']);

    // Reposiciones
    Route::post('/reposiciones', [ReposicionController::class, 'store']);
    Route::put('/reposiciones/{id}/completar', [ReposicionController::class, 'completarReposicion']);
});