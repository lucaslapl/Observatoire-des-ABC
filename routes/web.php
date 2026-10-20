<?php

use App\Http\Controllers\Admin\ActualiteController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\ProfilController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\FeedController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Routes web (Inertia + HTML)
|--------------------------------------------------------------------------
*/

Route::get('/', [PageController::class, 'index'])->name('home');
Route::get('/carte', [PageController::class, 'carte'])->name('carte');

Route::get('/robots.txt', fn () => response(
    implode("\n", [
        'User-agent: *',
        'Disallow: /api/',
        'Disallow: /admin',
        'Disallow: /login',
        'Disallow: /register',
        'Disallow: /profil',
        'Disallow: /forgot-password',
        'Disallow: /reset-password',
        'Disallow: /verification-email',
        'Disallow: /verify-email',
        'Disallow: /verify',
        '',
        'Sitemap: '.url('/sitemap.xml'),
        '',
    ])
)->header('Content-Type', 'text/plain; charset=UTF-8')
    ->header('Cache-Control', 'public, max-age=3600'))->name('robots');

Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');
Route::get('/feed.xml', FeedController::class)->name('feed');

Route::get('/verify', [PageController::class, 'verify'])->name('verify');

Route::get('/actualites', [PageController::class, 'actualites'])->name('actualites');
Route::get('/actualites/{actualite:slug}', [PageController::class, 'actualite'])->name('actualites.show');

Route::get('/abc/{projet:slug}', [PageController::class, 'projet'])->name('abc.show');
Route::get('/commune/{code}', [PageController::class, 'commune'])->name('commune.show');
Route::get('/departement/{code}', [PageController::class, 'departement'])->where('code', '[0-9a-zA-Z]{1,3}')->name('departement.show');
Route::get('/region/{slug}', [PageController::class, 'region'])->where('slug', '[a-z0-9-]+')->name('region.show');

Route::get('/a-propos', [PageController::class, 'aPropos'])->name('a-propos');
Route::get('/mentions-legales', [PageController::class, 'mentionsLegales'])->name('mentions-legales');
Route::get('/confidentialite', [PageController::class, 'confidentialite'])->name('confidentialite');

Route::get('/contact', [ContactController::class, 'create'])->name('contact');
Route::post('/contact', [ContactController::class, 'store'])
    ->middleware('throttle:contact')->name('contact.store');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:login');

    Route::get('/register', [RegisterController::class, 'create'])->name('register');
    Route::post('/register', [RegisterController::class, 'store'])->middleware('throttle:register');

    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:password-reset')->name('password.email');

    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->name('password.update');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');

    Route::get('/verification-email', [EmailVerificationController::class, 'notice'])
        ->name('verification.notice');

    Route::post('/verification-email/send', [EmailVerificationController::class, 'send'])
        ->middleware('throttle:6,1')->name('verification.send');

    Route::get('/verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/profil', [ProfilController::class, 'index'])->name('profil');
    Route::put('/profil/password', [ProfilController::class, 'updatePassword'])->name('profil.password');
    Route::delete('/profil', [ProfilController::class, 'destroy'])->name('profil.destroy');

    Route::middleware('role:admin')->group(function () {
        Route::get('/admin', [PageController::class, 'admin'])->name('admin');
        Route::post('/actualites', [ActualiteController::class, 'store'])->name('actualites.store');
        Route::put('/actualites/{actualite}', [ActualiteController::class, 'update'])->name('actualites.update');
        Route::delete('/actualites/{actualite}', [ActualiteController::class, 'destroy'])->name('actualites.destroy');
    });
});
