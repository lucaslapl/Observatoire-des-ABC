<?php

use App\Http\Controllers\Admin\ActualiteController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\PageController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Routes web (Inertia + HTML)
|--------------------------------------------------------------------------
*/

Route::get('/', [PageController::class, 'index'])->name('home');
Route::get('/verify', [PageController::class, 'verify'])->name('verify');
Route::get('/actualites', [PageController::class, 'actualites'])->name('actualites');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');

    Route::middleware('role:admin')->group(function () {
        Route::get('/admin', [PageController::class, 'admin'])->name('admin');
        Route::post('/actualites', [ActualiteController::class, 'store'])->name('actualites.store');
        Route::put('/actualites/{actualite}', [ActualiteController::class, 'update'])->name('actualites.update');
        Route::delete('/actualites/{actualite}', [ActualiteController::class, 'destroy'])->name('actualites.destroy');
    });
});
