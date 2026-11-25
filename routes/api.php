<?php

use App\Http\Controllers\Api\CodOrderImportController;
use App\Http\Controllers\Api\CodProductBulkCreateController;
use App\Http\Controllers\Api\CodProductCreateController;
use App\Http\Controllers\Api\CodProductUpdateController;
use App\Http\Controllers\Api\CodWhatsAppWebhookController;
use App\Http\Middleware\ForceJsonResponse;
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
    ->middleware([ForceJsonResponse::class, 'throttle:60,1'])
    ->name('api.cod.orders.store');

/**
 * COD System product API.
 *
 * POST /api/cod/products     — create a simple product (inactive by
 *                              default, nothing is published without
 *                              approval).
 * POST /api/cod/products/bulk — create up to 50 simple products in
 *                              one request (same rules per product,
 *                              failures do not stop the rest).
 * POST /api/cod/products/{id} — update one product's per-locale
 *                              content (name, url_key, descriptions,
 *                              meta) and/or stock, and/or append
 *                              images via `image_urls` (append-only,
 *                              at most 10 images in total). Price,
 *                              categories, status and multipart
 *                              `images` are NOT accepted here.
 * Authenticated with the product secret key from the COD System admin
 * settings (X-COD-Product-Key header or Bearer token), generated with
 * `php artisan cod:generate-product-key`. See
 * CodProductCreateController / CodProductBulkCreateController /
 * CodProductUpdateController for the payload formats.
 */
Route::post('cod/products', CodProductCreateController::class)
    ->middleware([ForceJsonResponse::class, 'throttle:60,1'])
    ->name('api.cod.products.store');

Route::post('cod/products/bulk', CodProductBulkCreateController::class)
    ->middleware([ForceJsonResponse::class, 'throttle:60,1'])
    ->name('api.cod.products.bulk');

Route::post('cod/products/{id}', CodProductUpdateController::class)
    ->middleware([ForceJsonResponse::class, 'throttle:60,1'])
    ->whereNumber('id')
    ->name('api.cod.products.update');

/**
 * COD System WhatsApp webhooks (phase 4).
 *
 * GET  /api/cod/whatsapp/meta       — Meta webhook verification
 *                                      (hub.mode / hub.verify_token /
 *                                      hub.challenge).
 * POST /api/cod/whatsapp/meta       — Meta incoming messages
 *                                      (signature X-Hub-Signature-256
 *                                      verified when the app secret is
 *                                      configured).
 * POST /api/cod/whatsapp/evolution  — Evolution API messages.upsert
 *                                      events (accepted only for the
 *                                      configured instance; Evolution
 *                                      has no webhook signature).
 * See CodWhatsAppWebhookController for details. These routes are
 * intentionally unauthenticated (webhooks carry no admin session)
 * and must always answer 200 quickly.
 */
Route::get('cod/whatsapp/meta', [CodWhatsAppWebhookController::class, 'verifyMeta'])
    ->middleware('throttle:120,1')
    ->name('api.cod.whatsapp.meta.verify');

Route::post('cod/whatsapp/meta', [CodWhatsAppWebhookController::class, 'handleMeta'])
    ->middleware('throttle:120,1')
    ->name('api.cod.whatsapp.meta');

Route::post('cod/whatsapp/evolution', [CodWhatsAppWebhookController::class, 'handleEvolution'])
    ->middleware('throttle:120,1')
    ->name('api.cod.whatsapp.evolution');
