<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Actualite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ActualiteController extends Controller
{
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

        return back();
    }

    public function destroy(Actualite $actualite): RedirectResponse
    {
        $actualite->delete();

        return back();
    }
}
