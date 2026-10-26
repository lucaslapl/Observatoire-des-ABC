<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\ApiV1Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JournalController extends Controller
{
    public function __construct(private ApiV1Service $api) {}

    /**
     * Journal public d'audit : vérifications, contributions validées,
     * fusions… dans le même ordre que sur le site (le plus récent
     * d'abord), paginé par curseur. Les auteurs sont anonymisés : les
     * contributions sont créditées collectivement, jamais nominativement.
     *
     * @group Journal
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:100',
            'format' => 'nullable|in:json',
        ], [
            'per_page.integer' => 'per_page doit être un entier.',
            'per_page.between' => 'per_page doit être compris entre 1 et 100.',
            'format.in' => 'Le paramètre format doit valoir json.',
        ]);

        $perPage = min(
            isset($validated['per_page']) ? (int) $validated['per_page'] : (int) config('api.per_page'),
            (int) config('api.max_per_page')
        );

        return $this->json($this->api->journal($perPage));
    }
}
