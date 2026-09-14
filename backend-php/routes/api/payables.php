<?php

// Mirrors backend/app/routers/payables.py. NOT yet converted (see
// docs/php-conversion-plan.md and PayablesService's class docblock):
// Payment Vouchers (POST /payments etc. -- blocked on GL posting +
// Bank), CSV/Excel/.docx export, "Email Purchase Order", un-GL.

use App\Http\Controllers\Api\AccountsPayableController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\SupplierInvoiceController;
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

    Route::get('/aging', [AccountsPayableController::class, 'agingReport']);
});
