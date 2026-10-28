<?php

namespace App\Http\Controllers;

use App\Services\ProjetsListeService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Liste publique « tous les ABC » (/abc) : recherche, filtres et tris sur
 * l'ensemble des projets, passés, présents et à venir. Tout vit dans la
 * query string (URLs partageables et indexables).
 */
class ProjetsListeController extends Controller
{
    public function __construct(private ProjetsListeService $liste) {}

    public function index(): Response
    {
        return Inertia::render('ProjetsListe', [
            'liste' => $this->liste->liste(request()->query()),
        ]);
    }
}
