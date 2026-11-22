<?php

use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\TokenController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Middleware\AuthenticateMerchant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

/*
|--------------------------------------------------------------------------
| Payment Facilitator API Routes (PRD Section 6.3)
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {
    Route::middleware(AuthenticateMerchant::class)->group(function () {
        // Tokenization (mock card/bank/ewallet)
        Route::post('/tokens', [TokenController::class, 'store']);

        // Transactions (Idempotent creation and payment)
        Route::post('/transactions', [TransactionController::class, 'store']);
        Route::post('/transactions/{uuid}/pay', [TransactionController::class, 'pay']);
        Route::get('/transactions/{uuid}', [TransactionController::class, 'show']);

        // Orders transactions
        Route::get('/orders/{uuid}/transactions', [TransactionController::class, 'byOrder']);

        // Audit Trail verification
        Route::get('/audit/verify', [AuditController::class, 'verify']);
        Route::get('/audit/transactions/{uuid}', [AuditController::class, 'transaction']);
    });
});
