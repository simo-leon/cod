<?php

use App\Http\Controllers\Api\CodOrderImportController;
use Illuminate\Support\Facades\Route;

/**
 * COD System import endpoint (phase 2).
 *
 * POST /api/cod/orders — receives store orders into the COD System.
 * Authenticated with the import secret key from the COD System admin
 * settings (X-COD-Import-Key header or Bearer token). See
 * CodOrderImportController for the payload format.
 */
Route::post('cod/orders', CodOrderImportController::class)
    ->name('api.cod.orders.store');
