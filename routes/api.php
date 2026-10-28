<?php

use App\Http\Controllers\Admin\BackupController;
use App\Http\Controllers\Admin\CollectController;
use App\Http\Controllers\Admin\ContributionController as AdminContributionController;
use App\Http\Controllers\Admin\ProjetController as AdminProjetController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\ContributionController;
use App\Http\Controllers\Api\DiagController;
use App\Http\Controllers\Api\DocController;
use App\Http\Controllers\Api\GeoJsonController;
use App\Http\Controllers\Api\MetaController;
use App\Http\Controllers\Api\StatsController;
use App\Http\Controllers\Api\V1\CommunesController;
use App\Http\Controllers\Api\V1\DepartementsController;
use App\Http\Controllers\Api\V1\JournalController;
use App\Http\Controllers\Api\V1\PatrimoineController;
use App\Http\Controllers\Api\V1\ProjetsController;
use App\Http\Controllers\Api\V1\RegionsController;
use App\Http\Controllers\Api\V1\StatsController as StatsV1Controller;
use App\Http\Controllers\Api\VerificationController;
use App\Http\Middleware\CacheApiResponse;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API publique v1 (lecture seule, Licence Ouverte 2.0)
|--------------------------------------------------------------------------
|
| Réutilisation ouverte par des tiers : sites, collectivités, recherche.
| Hors groupe « web » : pas de session ni de cookies. Toutes les
| réponses GET sont mises en cache (CacheApiResponse) et servies avec
| ETag + Cache-Control (304 sur If-None-Match). Throttling par IP
| (limiter « api-v1 », 60 req/min). CORS ouvert (config/cors.php).
| Le pool PHP-FPM dédié de la stack Docker route /api/v1/ (docker/php).
|
| Aucune donnée personnelle n'est exposée : le travail humain est
| crédité collectivement (« les contributeurs de l'Observatoire »).
*/
Route::prefix('v1')
    ->name('api.v1.')
    ->middleware(['throttle:api-v1', CacheApiResponse::class])
    ->group(function () {
        Route::get('/projets', [ProjetsController::class, 'index'])->name('projets.index');
        Route::get('/projets/{id}', [ProjetsController::class, 'show'])->name('projets.show');
        Route::get('/communes/{code}', [CommunesController::class, 'show'])->name('communes.show');
        Route::get('/departements/{code}', [DepartementsController::class, 'show'])->name('departements.show');
        Route::get('/regions/{code}', [RegionsController::class, 'show'])->name('regions.show');
        Route::get('/journal', [JournalController::class, 'index'])->name('journal.index');
        Route::get('/stats', [StatsV1Controller::class, 'index'])->name('stats.index');

        // Patrimoine écologique INPN : additif, même périmètre éditorial
        // que le site (zonages touchant au moins une commune ABC),
        // attribution MNHN sur chaque réponse (les données INPN ne sont
        // pas sous Licence Ouverte Etalab).
        Route::get('/communes/{code}/patrimoine', [PatrimoineController::class, 'commune'])
            ->name('patrimoine.commune');
        Route::get('/patrimoine/znieff', [PatrimoineController::class, 'znieffListing'])
            ->name('patrimoine.znieff.liste');
        Route::get('/patrimoine/znieff/{id}', [PatrimoineController::class, 'znieffShow'])
            ->where('id', '[0-9]{6,12}')->name('patrimoine.znieff.show');
        Route::get('/patrimoine/natura', [PatrimoineController::class, 'naturaListing'])
            ->name('patrimoine.natura.liste');
        Route::get('/patrimoine/natura/{sitecode}', [PatrimoineController::class, 'naturaShow'])
            ->where('sitecode', 'FR[0-9A-Z]{7}')->name('patrimoine.natura.show');
    });

// Documentation OpenAPI (HTML généré + spécification), hors throttling :
// pages statiques servies par DocController depuis public/docs.
Route::get('/doc', [DocController::class, 'show'])->name('api.doc');
Route::get('/doc/openapi.yaml', [DocController::class, 'spec'])->name('api.doc.openapi');

/*
|--------------------------------------------------------------------------
| Routes API (mêmes contrats que l'ancien serveur Node)
|--------------------------------------------------------------------------
*/

Route::middleware('web')->group(function () {

    Route::get('/abc.geojson', [GeoJsonController::class, 'index']);
    Route::get('/meta', [MetaController::class, 'index']);
    Route::get('/stats', [StatsController::class, 'index']);
    Route::get('/diag', [DiagController::class, 'index']);
    Route::get('/verifications', [VerificationController::class, 'show']);

    Route::get('/contributions', [ContributionController::class, 'index']);
    Route::post('/contributions', [ContributionController::class, 'store'])
        ->middleware(['auth', 'verified', 'throttle:10,60']);

    // Modération : verdicts directs et traitement des contributions.
    // Ouvert aux modérateurs (experts de confiance) en plus des admins.
    Route::middleware(['auth', 'role:admin|moderateur'])->group(function () {
        Route::post('/verifications', [VerificationController::class, 'store']);
        Route::get('/admin/contributions', [AdminContributionController::class, 'index']);
        Route::post('/admin/contributions/{id}/valider', [AdminContributionController::class, 'valider']);
        Route::post('/admin/contributions/{id}/refuser', [AdminContributionController::class, 'refuser']);
        Route::post('/admin/contributions/{id}/annuler', [AdminContributionController::class, 'annuler']);
        Route::post('/admin/contributions/{id}/retirer', [AdminContributionController::class, 'retirer']);
    });

    // Maintenance : administrateurs uniquement.
    Route::middleware(['auth', 'role:admin'])->group(function () {
        Route::post('/admin/backup', [BackupController::class, 'store']);
        Route::post('/admin/collect', [CollectController::class, 'store']);

        Route::delete('/admin/projets/{projet}', [AdminProjetController::class, 'destroy']);
        Route::post('/admin/projets/{projet}/fusion', [AdminProjetController::class, 'fusion']);
        Route::post('/admin/projets/{projet}/non-doublon', [AdminProjetController::class, 'nonDoublon']);
        Route::get('/admin/projets/recherche', [AdminProjetController::class, 'recherche']);
        Route::get('/admin/exclusions', [AdminProjetController::class, 'index']);
        Route::delete('/admin/exclusions/{projet}', [AdminProjetController::class, 'unexclude']);
        Route::get('/admin/exclusions/export', [AdminProjetController::class, 'export']);

        // Gestion du rôle modérateur (carte « Comptes ») : admin uniquement.
        Route::post('/admin/comptes/{user}/moderateur', [AdminUserController::class, 'promoteModerateur']);
        Route::delete('/admin/comptes/{user}/moderateur', [AdminUserController::class, 'demoteModerateur']);
    });

});
