<?php

// Mirrors backend/app/routers/payables.py.

use App\Http\Controllers\Api\AccountsPayableController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\SupplierInvoiceController;
use App\Http\Controllers\Api\SupplierPaymentController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('accounts-payable')->group(function () {
    Route::get('/purchase-orders', [PurchaseOrderController::class, 'index']);
    Route::get('/purchase-orders/export.csv', [PurchaseOrderController::class, 'exportCsv']);
    Route::get('/purchase-orders/export.xlsx', [PurchaseOrderController::class, 'exportExcel']);
    Route::post('/purchase-orders', [PurchaseOrderController::class, 'store']);
    Route::get('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show']);
    Route::post('/purchase-orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve']);
    Route::post('/purchase-orders/{purchaseOrder}/import-to-ap', [PurchaseOrderController::class, 'importToAp']);
    Route::get('/purchase-orders/{purchaseOrder}/export.docx', [PurchaseOrderController::class, 'exportDocx']);
    Route::post('/purchase-orders/{purchaseOrder}/email', [PurchaseOrderController::class, 'email']);

    Route::get('/bills', [SupplierInvoiceController::class, 'index']);
    Route::get('/bills/export.csv', [SupplierInvoiceController::class, 'exportCsv']);
    Route::get('/bills/export.xlsx', [SupplierInvoiceController::class, 'exportExcel']);
    Route::post('/bills', [SupplierInvoiceController::class, 'store']);
    Route::get('/bills/{bill}', [SupplierInvoiceController::class, 'show']);
    Route::post('/bills/{bill}/ungl', [SupplierInvoiceController::class, 'ungl']);
    Route::post('/bills/{bill}/rematch', [SupplierInvoiceController::class, 'rematch']);

    Route::get('/payments', [SupplierPaymentController::class, 'index']);
    Route::get('/payments/export.csv', [SupplierPaymentController::class, 'exportCsv']);
    Route::get('/payments/export.xlsx', [SupplierPaymentController::class, 'exportExcel']);
    Route::post('/payments', [SupplierPaymentController::class, 'store']);
    Route::get('/payments/{payment}', [SupplierPaymentController::class, 'show']);
    Route::post('/payments/{payment}/allocate', [SupplierPaymentController::class, 'allocate']);
    Route::post('/payments/{payment}/bank', [SupplierPaymentController::class, 'bank']);
    Route::post('/payments/{payment}/unbank', [SupplierPaymentController::class, 'unbank']);
    Route::post('/payments/{payment}/ungl', [SupplierPaymentController::class, 'ungl']);
    Route::get('/payments/{payment}/export.docx', [SupplierPaymentController::class, 'exportDocx']);
    Route::post('/payments/{payment}/email', [SupplierPaymentController::class, 'email']);

    Route::get('/aging', [AccountsPayableController::class, 'agingReport']);
    Route::get('/aging/export.csv', [AccountsPayableController::class, 'agingCsv']);
    Route::get('/aging/export.xlsx', [AccountsPayableController::class, 'agingExcel']);
});
