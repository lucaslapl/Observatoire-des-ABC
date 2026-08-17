<?php

use App\Http\Controllers\Admin\BackupController;
use App\Http\Controllers\Admin\CollectController;
use App\Http\Controllers\Admin\ContributionController as AdminContributionController;
use App\Http\Controllers\Api\ContributionController;
use App\Http\Controllers\Api\DiagController;
use App\Http\Controllers\Api\GeoJsonController;
use App\Http\Controllers\Api\MetaController;
use App\Http\Controllers\Api\StatsController;
use App\Http\Controllers\Api\VerificationController;
use Illuminate\Support\Facades\Route;

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
        ->middleware('throttle:10,60');

    Route::middleware(['auth', 'role:admin'])->group(function () {
        Route::post('/verifications', [VerificationController::class, 'store']);
        Route::get('/admin/contributions', [AdminContributionController::class, 'index']);
        Route::post('/admin/contributions/{id}/valider', [AdminContributionController::class, 'valider']);
        Route::post('/admin/contributions/{id}/refuser', [AdminContributionController::class, 'refuser']);
        Route::post('/admin/contributions/{id}/retirer', [AdminContributionController::class, 'retirer']);
        Route::post('/admin/backup', [BackupController::class, 'store']);
        Route::post('/admin/collect', [CollectController::class, 'store']);
    });

});
