<?php

// Mirrors backend/app/routers/payables.py. NOT yet converted (see
// docs/php-conversion-plan.md): CSV/Excel/.docx export, "Email
// Purchase Order"/"Email Payment Voucher".

use App\Http\Controllers\Api\AccountsPayableController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\SupplierInvoiceController;
use App\Http\Controllers\Api\SupplierPaymentController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('accounts-payable')->group(function () {
    Route::get('/purchase-orders', [PurchaseOrderController::class, 'index']);
    Route::post('/purchase-orders', [PurchaseOrderController::class, 'store']);
    Route::get('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show']);
    Route::post('/purchase-orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve']);
    Route::post('/purchase-orders/{purchaseOrder}/import-to-ap', [PurchaseOrderController::class, 'importToAp']);

    Route::get('/bills', [SupplierInvoiceController::class, 'index']);
    Route::post('/bills', [SupplierInvoiceController::class, 'store']);
    Route::get('/bills/{bill}', [SupplierInvoiceController::class, 'show']);
    Route::post('/bills/{bill}/ungl', [SupplierInvoiceController::class, 'ungl']);

    Route::get('/payments', [SupplierPaymentController::class, 'index']);
    Route::post('/payments', [SupplierPaymentController::class, 'store']);
    Route::get('/payments/{payment}', [SupplierPaymentController::class, 'show']);
    Route::post('/payments/{payment}/allocate', [SupplierPaymentController::class, 'allocate']);
    Route::post('/payments/{payment}/bank', [SupplierPaymentController::class, 'bank']);
    Route::post('/payments/{payment}/unbank', [SupplierPaymentController::class, 'unbank']);
    Route::post('/payments/{payment}/ungl', [SupplierPaymentController::class, 'ungl']);

    Route::get('/aging', [AccountsPayableController::class, 'agingReport']);
});
