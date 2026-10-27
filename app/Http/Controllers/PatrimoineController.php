<?php

namespace App\Http\Controllers;

use App\Services\PatrimoineFicheService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Fiches publiques du patrimoine écologique INPN, restreintes au
 * périmètre des communes ABC (ZNIEFF et sites Natura 2000 qui touchent
 * au moins une commune ayant un ABC).
 */
class PatrimoineController extends Controller
{
    public function __construct(private PatrimoineFicheService $fiches) {}

    public function index(): Response
    {
        return Inertia::render('Patrimoine', [
            'stats' => $this->fiches->index(),
        ]);
    }

    public function znieffListe(): Response
    {
        return Inertia::render('PatrimoineZnieffListe', [
            'listing' => $this->fiches->znieffListing($this->page()),
        ]);
    }

    public function znieffShow(string $idNational): Response
    {
        return Inertia::render('PatrimoineZnieff', [
            'znieff' => $this->fiches->znieff($idNational),
        ]);
    }

    public function naturaListe(): Response
    {
        return Inertia::render('PatrimoineNaturaListe', [
            'listing' => $this->fiches->naturaListing($this->page()),
        ]);
    }

    /**
     * Page courante depuis la query string (?page=N), bornée par le
     * service.
     */
    private function page(): int
    {
        return max(1, (int) request()->query('page', '1'));
    }

    public function naturaShow(string $sitecode): Response
    {
        return Inertia::render('PatrimoineNatura', [
            'site' => $this->fiches->natura($sitecode),
        ]);
    }
}
