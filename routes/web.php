<?php

use App\Http\Controllers\Admin\ActualiteController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Routes web (Inertia + HTML)
|--------------------------------------------------------------------------
*/

Route::get('/', [PageController::class, 'index'])->name('home');

Route::get('/robots.txt', fn () => response(
    implode("\n", [
        'User-agent: *',
        'Disallow: /api/',
        'Disallow: /admin',
        'Disallow: /login',
        'Disallow: /verify',
        '',
        'Sitemap: '.url('/sitemap.xml'),
        '',
    ])
)->header('Content-Type', 'text/plain; charset=UTF-8'))->name('robots');

Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');

Route::get('/verify', [PageController::class, 'verify'])->name('verify');

Route::get('/actualites', [PageController::class, 'actualites'])->name('actualites');
Route::get('/actualites/{actualite:slug}', [PageController::class, 'actualite'])->name('actualites.show');

Route::get('/abc/{projet:slug}', [PageController::class, 'projet'])->name('abc.show');
Route::get('/commune/{code}', [PageController::class, 'commune'])->name('commune.show');
Route::get('/departement/{code}', [PageController::class, 'departement'])->where('code', '[0-9a-zA-Z]{1,3}')->name('departement.show');
Route::get('/region/{slug}', [PageController::class, 'region'])->where('slug', '[a-z0-9-]+')->name('region.show');

Route::get('/mentions-legales', [PageController::class, 'mentionsLegales'])->name('mentions-legales');
Route::get('/confidentialite', [PageController::class, 'confidentialite'])->name('confidentialite');

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
