<?php

use App\Http\Controllers\BookkeepingController;
use Illuminate\Support\Facades\Route;

/**
 * Included from routes/web.php inside the auth + active + writable group.
 * role runs before the module switch so a non-admin is refused (403) even
 * when the module is off. Guests never reach this file's middleware: the
 * parent auth middleware redirects them to login.
 */
Route::middleware(['role:admin', 'module:bookkeeping'])->prefix('bookkeeping')->name('bookkeeping.')->group(function () {
    Route::get('/', [BookkeepingController::class, 'index'])->name('index');
    Route::post('/statements', [BookkeepingController::class, 'upload'])->name('statements.store');
    Route::get('/statements/{statement}', [BookkeepingController::class, 'download'])->whereNumber('statement')->name('statements.download');
    Route::post('/ledger', [BookkeepingController::class, 'markUpdated'])->name('ledger.update');
    Route::post('/payments', [BookkeepingController::class, 'paymentsRecorded'])->name('payments');
    Route::get('/brands', [BookkeepingController::class, 'brands'])->name('brands');
    Route::post('/brands', [BookkeepingController::class, 'addBrand'])->name('brands.add');
    Route::post('/brands/remove', [BookkeepingController::class, 'removeBrand'])->name('brands.remove');
    Route::post('/template', [BookkeepingController::class, 'saveTemplate'])->name('template.save');
    Route::post('/template/revert', [BookkeepingController::class, 'revertTemplate'])->name('template.revert');
    Route::post('/signature', [BookkeepingController::class, 'saveSignature'])->name('signature');
    Route::post('/signature/reset', [BookkeepingController::class, 'resetSignature'])->name('signature.reset');
    Route::post('/preview', [BookkeepingController::class, 'preview'])->name('preview');
    Route::post('/send', [BookkeepingController::class, 'send'])->middleware('throttle:10,1')->name('send');
});
