<?php

use App\Http\Controllers\GuestLoginController;
use Illuminate\Support\Facades\Route;

/*
 * Preview site only. routes/web.php loads this file ONLY when APP_ENV=staging and
 * PREVIEW_GUEST_LOGIN=true; the controller checks the same again.
 */
Route::get('/guest/{token}', [GuestLoginController::class, 'show'])->middleware('throttle:10,1')->name('preview.guest');
Route::post('/guest/{token}', GuestLoginController::class)->middleware('throttle:10,1')->name('preview.guest.go');
Route::redirect('/batches', '/batch-log');
