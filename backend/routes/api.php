<?php

use App\Http\Controllers\Api\AdminOldJewelleryController;
use App\Http\Controllers\Api\FastrrCatalogController;
use App\Http\Controllers\Api\FastrrWebhookController;
use App\Http\Controllers\Api\OldJewelleryRequestController;
use App\Http\Controllers\Api\VendorOldJewelleryController;
use App\Http\Controllers\Api\WalletController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // Shiprocket Fastrr Checkout reads our catalogue here and posts each
    // completed order back. Save these URLs under Dashboard → Custom
    // Endpoints; every call must carry the HMAC or FASTRR_CATALOG_TOKEN.
    Route::prefix('fastrr')->middleware(['fastrr', 'throttle:120,1'])->group(function () {
        Route::get('/products', [FastrrCatalogController::class, 'products']);
        Route::get('/collections', [FastrrCatalogController::class, 'collections']);
        Route::get('/collections/{collection}/products', [FastrrCatalogController::class, 'collectionProducts']);
        Route::post('/webhooks/order', [FastrrWebhookController::class, 'order']);
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/old-jewellery/requests', [OldJewelleryRequestController::class, 'store'])
            ->middleware('throttle:10,60');
        Route::get('/old-jewellery/requests', [OldJewelleryRequestController::class, 'index']);
        Route::get('/old-jewellery/requests/{oldJewelleryRequest:request_number}', [OldJewelleryRequestController::class, 'show']);
        Route::get('/old-jewellery/requests/{oldJewelleryRequest:request_number}/status', [OldJewelleryRequestController::class, 'status']);

        Route::get('/wallet', [WalletController::class, 'show']);
        Route::get('/wallet/transactions', [WalletController::class, 'transactions']);
        Route::get('/wallet/old-jewellery-credits', [WalletController::class, 'oldJewelleryCredits']);

        Route::prefix('admin/old-jewellery')->group(function () {
            Route::get('/', [AdminOldJewelleryController::class, 'index']);
            Route::get('/{oldJewelleryRequest:request_number}', [AdminOldJewelleryController::class, 'show']);
            Route::get('/{oldJewelleryRequest:request_number}/bids', [AdminOldJewelleryController::class, 'bids']);
            Route::post('/{oldJewelleryRequest:request_number}/bid', [AdminOldJewelleryController::class, 'bid']);
            Route::post('/{oldJewelleryRequest:request_number}/close', [AdminOldJewelleryController::class, 'close']);
        });
    });

    Route::prefix('vendor/old-jewellery/{token}')
        ->middleware(['vendor.token', 'throttle:30,1'])
        ->group(function () {
            Route::get('/', [VendorOldJewelleryController::class, 'show']);
            Route::post('/accept', [VendorOldJewelleryController::class, 'accept']);
            Route::post('/decline', [VendorOldJewelleryController::class, 'decline']);
        });
});
