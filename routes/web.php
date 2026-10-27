<?php

use App\Http\Controllers\Admin\ActualiteController;
use App\Http\Controllers\Admin\AdminPageController;
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
        // La documentation de l'API publique reste indexée malgré le
        // Disallow des endpoints (données volumineuses, pas du SEO).
        'Allow: /api/doc',
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

Route::get('/abc/{slug}', [PageController::class, 'projet'])->name('abc.show');
Route::get('/commune/{code}', [PageController::class, 'commune'])->name('commune.show');
Route::get('/departement/{code}', [PageController::class, 'departement'])->where('code', '[0-9a-zA-Z]{1,3}')->name('departement.show');
Route::get('/region/{slug}', [PageController::class, 'region'])->where('slug', '[a-z0-9-]+')->name('region.show');

Route::get('/methodologie', [PageController::class, 'methodologie'])->name('methodologie');
Route::get('/a-propos', [PageController::class, 'aPropos'])->name('a-propos');
Route::get('/mentions-legales', [PageController::class, 'mentionsLegales'])->name('mentions-legales');
Route::get('/confidentialite', [PageController::class, 'confidentialite'])->name('confidentialite');

// Page « API & réutilisation » : le chemin /api n'est pas utilisé par les
// routes API (elles vivent sous /api/v1 et /api/{ancien}).
Route::get('/api', [PageController::class, 'api'])->name('api-publique');

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
    Route::put('/profil/anonymat', [ProfilController::class, 'updateAnonymat'])->name('profil.anonymat');
    Route::delete('/profil', [ProfilController::class, 'destroy'])->name('profil.destroy');

    // Panneau /admin : une page par section, menu latéral commun. La
    // modération des contributions et les journaux d'audit sont ouverts aux
    // modérateurs (experts de confiance), le reste reste réservé aux admins
    // (comptes, exclusions, actualités, maintenance — protection par route,
    // pas seulement par masquage).
    Route::middleware('role:admin|moderateur')->group(function () {
        Route::get('/admin', [AdminPageController::class, 'dashboard'])->name('admin');
        Route::get('/admin/contributions', [AdminPageController::class, 'contributions'])->name('admin.contributions');
        Route::get('/admin/journaux', [AdminPageController::class, 'journaux'])->name('admin.journaux');
    });

    Route::middleware('role:admin')->group(function () {
        Route::get('/admin/actualites', [AdminPageController::class, 'actualites'])->name('admin.actualites');
        Route::get('/admin/exclusions', [AdminPageController::class, 'exclusions'])->name('admin.exclusions');
        Route::get('/admin/comptes', [AdminPageController::class, 'comptes'])->name('admin.comptes');
        Route::get('/admin/maintenance', [AdminPageController::class, 'maintenance'])->name('admin.maintenance');
    });

    Route::middleware('role:admin')->group(function () {
        Route::post('/actualites/preview', [ActualiteController::class, 'preview'])->name('actualites.preview');
        Route::post('/actualites', [ActualiteController::class, 'store'])->name('actualites.store');
        Route::put('/actualites/{actualite}', [ActualiteController::class, 'update'])->name('actualites.update');
        Route::delete('/actualites/{actualite}', [ActualiteController::class, 'destroy'])->name('actualites.destroy');
    });
});
