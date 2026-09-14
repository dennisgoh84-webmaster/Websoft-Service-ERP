<?php

// Stock / Inventory. Mirrors backend/app/routers/stock.py's
// `/api/stock` prefix and route paths exactly.
//
// Six Module Control keys gate this one router, so Group Authority can
// grant (say) warehouse staff Goods Receive Note access without giving
// them Stock Adjustment approval. Each route below carries the same
// key and the same minimum access level as the Python route's own
// require_module_access(...) call:
//
//   stock_master            -- item master, setup masters, warehouses, stock levels
//   goods_receive_note      -- GRN (receipt of goods from a supplier)
//   goods_transfer_note     -- GTN (inter-warehouse transfers)
//   goods_return_note       -- GRTN (return goods to a supplier)
//   stock_adjustment        -- adjust stock (damage, loss, count variance) -- INV-001
//   stock_operation_reports -- stock movements journal + valuation/reorder reports
//
// The gate is applied inside each controller action (Authority::
// requireModuleAccess) rather than as route middleware, the same
// pattern every other converted module in this backend uses.

use App\Http\Controllers\Api\GoodsReceiveNoteController;
use App\Http\Controllers\Api\GoodsReturnNoteController;
use App\Http\Controllers\Api\GoodsTransferNoteController;
use App\Http\Controllers\Api\StockAdjustmentController;
use App\Http\Controllers\Api\StockItemController;
use App\Http\Controllers\Api\StockReportController;
use App\Http\Controllers\Api\StockSetupController;
use App\Http\Controllers\Api\WarehouseController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.jwt')->prefix('stock')->group(function () {
    // ── Stock setup masters (stock_master) ──────────────────────────
    Route::get('/categories', [StockSetupController::class, 'indexCategories']);
    Route::post('/categories', [StockSetupController::class, 'storeCategory']);
    Route::patch('/categories/{category}', [StockSetupController::class, 'updateCategory']);
    Route::patch('/categories/{category}/toggle', [StockSetupController::class, 'toggleCategory']);

    Route::get('/groups', [StockSetupController::class, 'indexGroups']);
    Route::post('/groups', [StockSetupController::class, 'storeGroup']);
    Route::patch('/groups/{group}', [StockSetupController::class, 'updateGroup']);
    Route::patch('/groups/{group}/toggle', [StockSetupController::class, 'toggleGroup']);

    Route::get('/brands', [StockSetupController::class, 'indexBrands']);
    Route::post('/brands', [StockSetupController::class, 'storeBrand']);
    Route::patch('/brands/{brand}', [StockSetupController::class, 'updateBrand']);
    Route::patch('/brands/{brand}/toggle', [StockSetupController::class, 'toggleBrand']);
    Route::get('/brands/{brand}/models', [StockSetupController::class, 'indexModels']);

    Route::post('/models', [StockSetupController::class, 'storeModel']);
    Route::patch('/models/{model}', [StockSetupController::class, 'updateModel']);
    Route::patch('/models/{model}/toggle', [StockSetupController::class, 'toggleModel']);

    Route::get('/usages', [StockSetupController::class, 'indexUsages']);
    Route::post('/usages', [StockSetupController::class, 'storeUsage']);
    Route::patch('/usages/{usage}', [StockSetupController::class, 'updateUsage']);
    Route::patch('/usages/{usage}/toggle', [StockSetupController::class, 'toggleUsage']);

    // ── Stock item attachments (stock_master) ───────────────────────
    // Declared before /items/{item} so the literal path segments win.
    Route::post('/items/{item}/attachments', [StockItemController::class, 'uploadAttachment']);
    Route::delete('/items/{item}/attachments/{attachment}', [StockItemController::class, 'deleteAttachment']);
    Route::get('/items/{item}/attachments/{attachment}/download', [StockItemController::class, 'downloadAttachment']);

    // ── Warehouses (stock_master) ───────────────────────────────────
    Route::get('/warehouses', [WarehouseController::class, 'index']);
    Route::post('/warehouses', [WarehouseController::class, 'store']);
    Route::patch('/warehouses/{warehouse}', [WarehouseController::class, 'update']);

    // ── Stock items + levels (stock_master) ─────────────────────────
    Route::get('/items', [StockItemController::class, 'index']);
    Route::post('/items', [StockItemController::class, 'store']);
    Route::get('/items/{item}', [StockItemController::class, 'show']);
    Route::patch('/items/{item}', [StockItemController::class, 'update']);

    Route::get('/levels', [StockItemController::class, 'levels']);

    // ── Goods Receive Note (goods_receive_note) ─────────────────────
    Route::get('/grn', [GoodsReceiveNoteController::class, 'index']);
    Route::post('/grn', [GoodsReceiveNoteController::class, 'store']);
    Route::get('/grn/{grn}', [GoodsReceiveNoteController::class, 'show']);
    Route::post('/grn/{grn}/confirm', [GoodsReceiveNoteController::class, 'confirm']);

    // ── Goods Transfer Note (goods_transfer_note) ───────────────────
    // No GET /gtn/{id} in either backend -- the screen reads the list.
    Route::get('/gtn', [GoodsTransferNoteController::class, 'index']);
    Route::post('/gtn', [GoodsTransferNoteController::class, 'store']);
    Route::post('/gtn/{gtn}/confirm', [GoodsTransferNoteController::class, 'confirm']);

    // ── Goods Return Note (goods_return_note) ───────────────────────
    Route::get('/grtn', [GoodsReturnNoteController::class, 'index']);
    Route::post('/grtn', [GoodsReturnNoteController::class, 'store']);
    Route::post('/grtn/{grtn}/confirm', [GoodsReturnNoteController::class, 'confirm']);

    // ── Stock Adjustment -- INV-001 (stock_adjustment) ──────────────
    Route::get('/adjustments', [StockAdjustmentController::class, 'index']);
    Route::post('/adjustments', [StockAdjustmentController::class, 'store']);
    Route::post('/adjustments/{adjustment}/submit', [StockAdjustmentController::class, 'submit']);
    Route::post('/adjustments/{adjustment}/approve', [StockAdjustmentController::class, 'approve']);
    Route::post('/adjustments/{adjustment}/reject', [StockAdjustmentController::class, 'reject']);

    // ── Stock reports (stock_operation_reports) ─────────────────────
    // The movements journal is gated by the REPORTS key, not
    // stock_master -- same as the Python router.
    Route::get('/movements', [StockReportController::class, 'movements']);
    Route::get('/reports/valuation', [StockReportController::class, 'valuation']);
    Route::get('/reports/reorder', [StockReportController::class, 'reorder']);
});
