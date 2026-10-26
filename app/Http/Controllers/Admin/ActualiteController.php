<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Actualite;
use App\Services\MarkdownService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ActualiteController extends Controller
{
    public function __construct(private MarkdownService $markdown) {}

    /**
     * Aperçu du rendu Markdown, utilisé par l'éditeur de l'admin.
     * Même service que la page publique : l'aperçu est fidèle au rendu final.
     */
    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'contenu' => ['nullable', 'string'],
        ]);

        return response()->json([
            'html' => $this->markdown->toHtml($validated['contenu'] ?? ''),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'titre' => ['required', 'string', 'max:255'],
            'contenu' => ['required', 'string'],
            'statut' => ['required', 'in:publie,masque'],
            'date_publication' => ['nullable', 'date'],
        ]);

        Actualite::create([
            'titre' => $validated['titre'],
            'slug' => Str::slug($validated['titre']),
            'contenu' => $validated['contenu'],
            'auteur_id' => $request->user()?->id,
            'statut' => $validated['statut'],
            'date_publication' => $validated['date_publication'] ?? now(),
        ]);

        $this->forgetCachedPages();

        return back();
    }

    public function update(Request $request, Actualite $actualite): RedirectResponse
    {
        $validated = $request->validate([
            'titre' => ['required', 'string', 'max:255'],
            'contenu' => ['required', 'string'],
            'statut' => ['required', 'in:publie,masque'],
            'date_publication' => ['nullable', 'date'],
        ]);

        $actualite->update([
            'titre' => $validated['titre'],
            'slug' => Str::slug($validated['titre']),
            'contenu' => $validated['contenu'],
            'statut' => $validated['statut'],
            'date_publication' => $validated['date_publication'] ?? now(),
        ]);

        $this->forgetCachedPages();

        return back();
    }

    public function destroy(Actualite $actualite): RedirectResponse
    {
        $actualite->delete();

        $this->forgetCachedPages();

        return back();
    }

    /**
     * Le sitemap et le flux Atom listent les actualités mais sont servis
     * depuis un cache : sans invalidation, une publication ne serait
     * visible qu'à l'expiration (12 h / 1 h).
     */
    private function forgetCachedPages(): void
    {
        Cache::forget('abc:sitemap');
        Cache::forget('abc:feed');
    }
}
