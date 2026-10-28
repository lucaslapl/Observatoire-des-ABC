<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\ApiV1Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Patrimoine écologique INPN en lecture : ZNIEFF, sites Natura 2000 et
 * aires protégées, mêmes faits bruts que le site et même périmètre
 * éditorial (zonages touchant au moins une commune ABC). Chaque
 * réponse porte l'attribution INPN/MNHN et la version de référence de
 * la base ; les espèces confidentielles ne sont jamais diffusées.
 */
class PatrimoineController extends Controller
{
    public function __construct(private ApiV1Service $api) {}

    /**
     * Patrimoine écologique d'une commune : ZNIEFF, sites Natura 2000
     * et aires protégées la touchant, faits bruts INPN avec attribution
     * MNHN et version de référence de chaque base.
     *
     * @group Patrimoine INPN
     *
     * @urlParam code string required Code INSEE de la commune (ex. 35282). Example: 12045
     */
    public function commune(string $code): JsonResponse
    {
        return $this->json(['data' => $this->api->patrimoineCommune($code)]);
    }

    /**
     * Liste paginée des ZNIEFF éligibles (touchant au moins une commune
     * ayant un ABC), 100 par page.
     *
     * @group Patrimoine INPN
     *
     * @queryParam page int Page demandée (bornée par le service). Example: 1
     */
    public function znieffListing(Request $request): JsonResponse
    {
        return $this->json(['data' => $this->api->patrimoineZnieffListing($this->page($request))]);
    }

    /**
     * Fiche ZNIEFF : intérêt écologique, communes ABC concernées,
     * espèces déterminantes (hors confidentielles), parent et enfants.
     * 404 si la zone ne touche aucune commune ABC.
     *
     * @group Patrimoine INPN
     *
     * @urlParam id string required Identifiant national ZNIEFF (ex. 240009732). Example: 730010131
     */
    public function znieffShow(string $id): JsonResponse
    {
        return $this->json(['data' => $this->api->patrimoineZnieff($id)]);
    }

    /**
     * Liste paginée des sites Natura 2000 éligibles (touchant au moins
     * une commune ayant un ABC), 100 par page.
     *
     * @group Patrimoine INPN
     *
     * @queryParam page int Page demandée (bornée par le service). Example: 1
     */
    public function naturaListing(Request $request): JsonResponse
    {
        return $this->json(['data' => $this->api->patrimoineNaturaListing($this->page($request))]);
    }

    /**
     * Fiche Natura 2000 : habitats et espèces de la directive recensés
     * sur le site, DOCOB, communes ABC concernées. 404 si le site ne
     * touche aucune commune ABC.
     *
     * @group Patrimoine INPN
     *
     * @urlParam sitecode string required Code du site (ex. FR8212001). Example: FR5310086
     */
    public function naturaShow(string $sitecode): JsonResponse
    {
        return $this->json(['data' => $this->api->patrimoineNatura($sitecode)]);
    }

    /**
     * Page courante depuis la query string (?page=N), bornée par les
     * services.
     */
    private function page(Request $request): int
    {
        return max(1, (int) $request->query('page', '1'));
    }
}
