<?php

namespace App\Observers;

use App\Support\ApiCache;
use Illuminate\Database\Eloquent\Model;

/**
 * Invalide le cache applicatif de l'API v1 dès qu'une donnée publiée
 * change : projets, communes, vérifications, contributions et journal
 * d'audit. Les écritures massives (upserts du collect, mass updates)
 * contournent les événements de modèle : elles invalident explicitement
 * en fin de traitement (CollectService, StatusRecomputeService…).
 */
class ApiCacheObserver
{
    public function saved(Model $model): void
    {
        ApiCache::invalidate();
    }

    public function deleted(Model $model): void
    {
        ApiCache::invalidate();
    }
}
